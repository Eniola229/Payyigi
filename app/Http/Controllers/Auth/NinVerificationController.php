<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Services\Korapay\KorapayService;
use App\Services\Termii\TermiiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class NinVerificationController extends Controller
{
    public function __construct(
        private readonly KorapayService $korapay,
        private readonly TermiiService  $termii,
    ) {}

    /**
     * STEP 1 — User submits their NIN.
     *
     * Flow:
     * 1. Call Korapay NIN Lookup API with the NIN
     * 2. Korapay returns the phone_number registered to that NIN in the NIMC database
     * 3. We cache that NIN-linked phone temporarily
     * 4. We trigger a voice OTP call via Termii to that NIN-linked phone
     *    (Termii generates the PIN and calls the number to read it aloud)
     * 5. We store the returned pin_id for later verification
     * 6. Return a masked version of the phone to the user
     */
    public function initiateVerification(Request $request): JsonResponse
    {
        $request->validate([
            'nin' => ['required', 'string', 'regex:/^\d{11}$/'],
        ]);

        $user = $request->user();

        if ($user->nin_verified) {
            return response()->json(['message' => 'NIN already verified.'], 422);
        }

        $throttleKey = "nin_initiate:{$user->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Too many attempts. Please try again in {$seconds} seconds.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60 * 15);

        // ── Step 1: Call Korapay NIN Lookup ───────────────────────────────────
        try {
            $ninData = $this->korapay->verifyNin(
                nin:          $request->nin,
                validateData: true,
                firstName:    $user->first_name,
                lastName:     $user->last_name,
                dateOfBirth:  $user->date_of_birth,
            );
        } catch (\Exception $e) {
            Log::warning('NIN lookup failed for user', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to verify NIN. Please ensure it is correct and try again.',
            ], 422);
        }

        // ── Step 2: Extract phone from Korapay response ───────────────────────
        $ninPhone = $ninData['phone_number'] ?? null;

        // Log::info('NIN lookup phone number', [
        //     'user_id'              => $user->id,
        //     'nin_last4'            => substr($request->nin, -4),
        //     'returned_phone_last4' => $ninPhone ? substr($ninPhone, -4) : 'null',
        // ]);

        if (!$ninPhone) {
            return response()->json([
                'message' => 'No phone number found for this NIN. Please contact support.',
            ], 422);
        }

        // ── Step 3: Cache NIN + NIN-linked phone temporarily (15 min) ─────────
        Cache::put("nin_verification:{$user->id}", [
            'nin'       => $request->nin,
            'nin_phone' => $ninPhone,
            'nin_data'  => [
                'first_name'    => $ninData['first_name']    ?? null,
                'last_name'     => $ninData['last_name']     ?? null,
                'middle_name'   => $ninData['middle_name']   ?? null,
                'date_of_birth' => $ninData['date_of_birth'] ?? null,
                'gender'        => $ninData['gender']        ?? null,
                'reference'     => $ninData['reference']     ?? null,
            ],
        ], now()->addMinutes(15));

        // ── Step 4: Trigger voice OTP via Termii ──────────────────────────────
        // Termii generates the PIN and calls the number to read it aloud.
        // We get back a pin_id which is used to verify what the user enters.
        $result = $this->termii->sendVoiceToken($ninPhone);

        if (!$result['success']) {
            return response()->json([
                'message' => 'Failed to send verification call. Please try again.',
            ], 500);
        }

        // ── Step 5: Store pin_id for verification ─────────────────────────────
        // We no longer store the code locally — Termii owns the PIN.
        // We store pin_id in OtpCode.code field (or you can add a pin_id column).
        OtpCode::where('user_id', $user->id)
               ->where('purpose', 'nin_verification')
               ->where('used', false)
               ->update(['used' => true]);

        OtpCode::create([
            'user_id'    => $user->id,
            'code'       => $result['pin_id'], // stores Termii pin_id, not the actual PIN
            'phone'      => $ninPhone,
            'purpose'    => 'nin_verification',
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(10),
        ]);

        AuditLog::record('user.nin_verification_initiated', [
            'user_id'    => $user->id,
            'new_values' => [
                'nin_last4'       => substr($request->nin, -4),
                'nin_phone_last4' => substr($ninPhone, -4),
                'korapay_ref'     => $ninData['reference'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'A verification code will be read to you via phone call on the number registered to your NIN.',
            'data'    => [
                'phone_hint' => $this->maskPhone($ninPhone),
            ],
        ]);
    }

    /**
     * STEP 2 — User submits the PIN they heard on the voice call.
     * We verify it against Termii's Verify Token API using the stored pin_id.
     */
    public function confirmVerification(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $user = $request->user();

        if ($user->nin_verified) {
            return response()->json(['message' => 'NIN already verified.'], 422);
        }

        $throttleKey = "nin_confirm:{$user->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60 * 15);

        $cached = Cache::get("nin_verification:{$user->id}");

        if (!$cached) {
            return response()->json([
                'message' => 'Verification session expired. Please submit your NIN again.',
            ], 422);
        }

        // Retrieve the stored pin_id
        $otp = OtpCode::where('user_id', $user->id)
                      ->where('purpose', 'nin_verification')
                      ->valid()
                      ->latest()
                      ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'OTP has expired. Please start verification again.',
            ], 422);
        }

        // ── Verify against Termii's API (not a local comparison) ─────────────
        $verified = $this->termii->verifyToken($otp->code, $request->code);

        if (!$verified) {
            $otp->incrementAttempts();
            $remaining = max(0, 3 - $otp->fresh()->attempts);

            if ($remaining === 0) {
                return response()->json([
                    'message' => 'Too many incorrect attempts. Please request a new code.',
                ], 422);
            }

            return response()->json([
                'message' => "Incorrect code. {$remaining} attempt(s) remaining.",
            ], 422);
        }

        // PIN correct — verify the NIN
        $otp->markUsed();
        RateLimiter::clear($throttleKey);

        $user->update([
            'nin'             => $cached['nin'],
            'nin_verified'    => true,
            'nin_verified_at' => now(),
            'nin_phone'       => $cached['nin_phone'],
            'kyc_level' => 'completed',
        ]);

        Cache::forget("nin_verification:{$user->id}");
        Cache::forget("nin_initiate:{$user->id}");

        AuditLog::record('user.nin_verified', [
            'user_id'    => $user->id,
            'new_values' => [
                'nin_last4'       => substr($cached['nin'], -4),
                'nin_phone_last4' => substr($cached['nin_phone'], -4),
                'korapay_ref'     => $cached['nin_data']['reference'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'NIN verified successfully. Welcome fully onboard.',
        ]);
    }

    /**
     * Resend OTP — triggers a new voice call (max 2 times per 10 minutes)
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->nin_verified) {
            return response()->json(['message' => 'NIN already verified.'], 422);
        }

        $throttleKey = "nin_resend:{$user->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, 2)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Please wait {$seconds} seconds before requesting another call.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60 * 10);

        $cached = Cache::get("nin_verification:{$user->id}");

        if (!$cached) {
            return response()->json([
                'message' => 'Session expired. Please submit your NIN again.',
            ], 422);
        }

        $ninPhone = $cached['nin_phone'];

        OtpCode::where('user_id', $user->id)
               ->where('purpose', 'nin_verification')
               ->where('used', false)
               ->update(['used' => true]);

        $result = $this->termii->sendVoiceToken($ninPhone);

        if (!$result['success']) {
            return response()->json([
                'message' => 'Failed to send verification call. Please try again.',
            ], 500);
        }

        OtpCode::create([
            'user_id'    => $user->id,
            'code'       => $result['pin_id'],
            'phone'      => $ninPhone,
            'purpose'    => 'nin_verification',
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'message'    => 'A new verification call is being placed.',
            'phone_hint' => $this->maskPhone($ninPhone),
        ]);
    }

    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($phone, 0, 4) . str_repeat('*', $len - 8) . substr($phone, -4);
    }
}