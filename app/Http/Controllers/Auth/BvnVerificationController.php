<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\Korapay\KorapayService;

class BvnVerificationController extends Controller
{
    /**
     * Minimum face match confidence score (0–1) to accept as verified.
     * Korapay returns a confidence score on the selfie match.
     * 0.75 = 75% — adjust based on your risk tolerance.
     */
    private const MIN_CONFIDENCE = 0.75;

    public function __construct(
        private readonly KorapayService $korapay,
    ) {}

    /**
     * Single-step BVN verification with facial matching.
     *
     * Flow:
     * 1. User submits their BVN + a base64 selfie image
     * 2. We call Korapay BVN Lookup with facial matching enabled
     * 3. Korapay matches the selfie against the photo on record in the CBN database
     * 4. If selfie.match === true AND confidence >= threshold → mark BVN verified
     * 5. No OTP needed — the face IS the proof of ownership
     *
     * Why this is secure:
     * - The selfie is matched against the government database photo for THAT BVN
     * - A fraudster cannot pass verification by simply knowing someone else's BVN number
     * - They would need to physically look like the registered BVN holder
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'bvn'    => ['required', 'string', 'regex:/^\d{11}$/'],
            'selfie' => ['required', 'string'], // base64 encoded image: data:image/jpeg;base64,...
        ]);

        $user = $request->user();

        // Already verified via BVN or NIN — nothing to do
        if ($user->bvn_verified || $user->nin_verified) {
            return response()->json([
                'message' => 'Identity already verified.',
            ], 422);
        }

        // ── Throttle: max 3 attempts per 10 minutes ───────────────────────────
        // Facial matching is expensive (API cost + compute). Limit attempts hard.
        $throttleKey = "bvn_verify:{$user->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Too many attempts. Please try again in {$seconds} seconds.",
            ], 429);
        }
        RateLimiter::hit($throttleKey, 60 * 10);

        // ── Validate selfie format ────────────────────────────────────────────
        // Must be a valid base64 image string. We do a lightweight check here.
        if (!$this->isValidBase64Image($request->selfie)) {
            return response()->json([
                'message' => 'Invalid selfie format. Please provide a valid base64-encoded image.',
            ], 422);
        }

        // ── Call Korapay BVN + Facial Match ───────────────────────────────────
        try {
            $bvnData = $this->korapay->verifyBvn(
                bvn:         $request->bvn,
                selfie:      $request->selfie,
                firstName:   $user->first_name,
                lastName:    $user->last_name,
                dateOfBirth: $user->date_of_birth,
            );
        } catch (\Exception $e) {
            Log::warning('BVN facial verification failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to verify BVN. Please ensure your BVN is correct and try again.',
            ], 422);
        }

        // ── Evaluate facial match result ──────────────────────────────────────
        $selfieValidation = $bvnData['validation']['selfie'] ?? null;
        $selfieMatch       = $selfieValidation['match'] ?? false;
        $confidenceRating  = $selfieValidation['confidence_rating'] ?? 0;
        $confidence        = $confidenceRating / 100;

        Log::info('BVN facial match result', [
            'user_id'    => $user->id,
            'bvn_last4'  => substr($request->bvn, -4),
            'match'      => $selfieMatch,
            'confidence' => $confidence,
        ]);

    
        if (!$selfieMatch || $confidence < self::MIN_CONFIDENCE) {
            AuditLog::record('user.bvn_face_match_failed', [
                'user_id'    => $user->id,
                'new_values' => [
                    'bvn_last4'   => substr($request->bvn, -4),
                    'match'       => $selfieMatch,
                    'confidence'  => $confidenceRating,
                    'korapay_ref' => $bvnData['reference'] ?? null,
                ],
            ]);

            return response()->json([
                'message' => 'Face verification failed. The selfie provided does not match the photo on record for this BVN.',
            ], 422);
        }

        // ── Face matched — mark BVN as verified ────────────────────────────
        $user->update([
            'bvn'          => $request->bvn,  // auto-encrypted via cast
            'bvn_verified' => true,
        ]);

        AuditLog::record('user.bvn_verified', [
            'user_id'    => $user->id,
            'new_values' => [
                'bvn_last4'   => substr($request->bvn, -4),
                'confidence'  => $confidenceRating,
                'korapay_ref' => $bvnData['reference'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'BVN verified successfully. Welcome fully onboard.',
        ]);
    }

    /**
     * Quick sanity check on the base64 selfie string.
     * We don't fully decode it here — just ensure it looks like a valid data URI
     * or raw base64 before sending it to Korapay.
     */
    private function isValidBase64Image(string $selfie): bool
    {
        // Accept data URI format: data:image/jpeg;base64,/9j/...
        if (str_starts_with($selfie, 'data:image/')) {
            $parts = explode(',', $selfie, 2);
            return isset($parts[1]) && base64_decode($parts[1], strict: true) !== false;
        }

        // Accept raw base64
        return base64_decode($selfie, strict: true) !== false;
    }
}