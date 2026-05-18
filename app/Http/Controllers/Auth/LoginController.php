<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Security\DeviceService;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(
        private readonly DeviceService     $deviceService,
        private readonly TwoFactorService  $twoFactorService,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $this->ensureNotThrottled($request);

        $user = User::where('email', strtolower($request->email))->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($this->throttleKey($request), 60 * 15);

            AuditLog::record('auth.login_failed', [
                'user_id'    => $user?->id,
                'new_values' => ['email' => $request->email],
            ]);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Check if account is active
        if (!$user->isAccountActive()) {
            $reason = $user->is_suspended ? "Account suspended: {$user->suspension_reason}" : 'Account inactive.';
            return response()->json(['message' => $reason], 403);
        }

        // Check email verified
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message'        => 'Please verify your email address before logging in.',
                'requires_email_verification' => true,
            ], 403);
        }

        RateLimiter::clear($this->throttleKey($request));

        // ── Device fingerprinting ────────────────────────────────────────────
        $fingerprint = $request->header('X-Device-Fingerprint') ?? $this->deviceService->generateFingerprint($request);
        $device      = $this->deviceService->findOrCreateDevice($user, $request, $fingerprint);

        // ── 2FA check: new/untrusted device ──────────────────────────────────
        if ($user->hasTwoFactorEnabled() && !$device->is_trusted) {
            // Issue a short-lived 2FA challenge token
            $challengeToken = $this->twoFactorService->createChallenge($user, $request, $fingerprint);

            AuditLog::record('auth.two_factor_required', ['user_id' => $user->id]);

            return response()->json([
                'message'          => 'Two-factor authentication required for this device.',
                'requires_2fa'     => true,
                'two_factor_token' => $challengeToken, // used in next step
            ], 200);
        }

        // ── Issue token ──────────────────────────────────────────────────────
        $token = $user->createToken(
            name: $device->device_name ?? 'API Token',
            abilities: ['*'],
            expiresAt: now()->addDays(30),
        );

        // Store fingerprint on token for later verification
        $token->accessToken->device_fingerprint = $fingerprint;
        $token->accessToken->save();

        $device->markUsed();

        // Update last login
        $user->update([
            'last_login_ip'     => $request->ip(),
            'last_login_at'     => now(),
            'last_login_device' => $device->device_name,
        ]);

        AuditLog::record('auth.login_success', ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Login successful.',
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => now()->addDays(30)->toISOString(),
                'user'       => [
                    'id'           => $user->id,
                    'name'         => $user->full_name,
                    'email'        => $user->email,
                    'nin_verified' => $user->nin_verified,
                    'bvn_verified' => $user->bvn_verified,
                    'two_factor_enabled' => $user->two_factor_enabled,
                ],
            ],
        ]);
    }

    private function ensureNotThrottled(LoginRequest $request): void
    {
        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, config('auth.login_throttle_attempts', 5))) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ]);
        }
    }

    private function throttleKey(LoginRequest $request): string
    {
        return 'login|' . strtolower($request->email) . '|' . $request->ip();
    }
}
