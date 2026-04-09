<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Services\Korapay\KorapayService;
use App\Services\Twilio\TwilioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class NinVerificationController extends Controller
{
    public function __construct(
        private readonly KorapayService $korapay,
        private readonly TwilioService  $twilio,
    ) {}

    /**
     * STEP 1 — User submits their NIN.
     *
     * Flow:
     * 1. Call Korapay NIN Lookup API with the NIN
     * 2. Korapay returns the phone_number registered to that NIN in the NIMC database
     * 3. We cache that NIN-linked phone temporarily
     * 4. We send a 6-digit OTP via Twilio SMS to that NIN-linked phone
     * 5. Return a masked version of the phone to the user so they know where to look
     *
     * This ensures only the real NIN owner can verify — they must receive the OTP
     * on the phone NIMC has on record for that NIN.
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

        // ── Throttle: max 3 attempts per 15 minutes ──────────────────────────
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
        // Korapay returns the phone_number registered to this NIN in the NIMC DB.
        // We send OTP to THIS number — not the user's registered phone.
        // This proves the person submitting the NIN actually owns it.
        $ninPhone = $ninData['phone_number'] ?? null;

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

        // ── Step 4: Generate OTP and save to DB ───────────────────────────────
        OtpCode::where('user_id', $user->id)
               ->where('purpose', 'nin_verification')
               ->where('used', false)
               ->update(['used' => true]);

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id'    => $user->id,
            'code'       => $code,
            'phone'      => $ninPhone,
            'purpose'    => 'nin_verification',
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(10),
        ]);

        // ── Step 5: Send OTP via Twilio to the NIN-linked phone ───────────────
        $sent = $this->twilio->sendSms(
            $ninPhone,
            "Your PayYigi NIN verification code is: {$code}. Valid for 10 minutes. Do not share this with anyone."
        );

        if (!$sent) {
            return response()->json([
                'message' => 'Failed to send verification code. Please try again.',
            ], 500);
        }

        AuditLog::record('user.nin_verification_initiated', [
            'user_id'    => $user->id,
            'new_values' => [
                'nin_last4'       => substr($request->nin, -4),
                'nin_phone_last4' => substr($ninPhone, -4),
                'korapay_ref'     => $ninData['reference'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'A verification code has been sent to the phone number registered to your NIN.',
            'data'    => [
                'phone_hint' => $this->maskPhone($ninPhone),
            ],
        ]);
    }

    /**
     * STEP 2 — User submits the OTP received on their NIN-linked phone.
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

        // Retrieve cached NIN session
        $cached = Cache::get("nin_verification:{$user->id}");

        if (!$cached) {
            return response()->json([
                'message' => 'Verification session expired. Please submit your NIN again.',
            ], 422);
        }

        // Validate OTP
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

        if ($otp->code !== $request->code) {
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

        // ✅ OTP correct — verify the NIN
        $otp->markUsed();
        RateLimiter::clear($throttleKey);

        $user->update([
            'nin'             => $cached['nin'],       // auto-encrypted via cast
            'nin_verified'    => true,
            'nin_verified_at' => now(),
            'nin_phone'       => $cached['nin_phone'],
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
            'message' => 'NIN verified successfully. You can now proceed to complete KYC.',
        ]);
    }

    /**
     * Resend OTP (max 2 times per 10 minutes)
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
                'message' => "Please wait {$seconds} seconds before requesting another code.",
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

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'user_id'    => $user->id,
            'code'       => $code,
            'phone'      => $ninPhone,
            'purpose'    => 'nin_verification',
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->twilio->sendSms(
            $ninPhone,
            "Your PayYigi NIN verification code is: {$code}. Valid for 10 minutes. Do not share this with anyone."
        );

        return response()->json([
            'message'    => 'A new code has been sent.',
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
