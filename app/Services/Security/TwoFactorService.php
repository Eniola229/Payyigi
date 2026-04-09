<?php

namespace App\Services\Security;

use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\TwoFactorChallengeNotification;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OTPHP\TOTP;
 
class TwoFactorService
{
    private const CHALLENGE_TTL   = 10 * 60; // 10 minutes
    private const OTP_EXPIRY      = 10 * 60; // 10 minutes

    /**
     * Create a 2FA challenge for login from new device.
     * Returns a short-lived challenge token.
     */
    public function createChallenge(User $user, Request $request, string $fingerprint): string
    {
        // Generate OTP and store in DB
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Invalidate previous unused OTPs for this user/purpose
        OtpCode::where('user_id', $user->id)
               ->where('purpose', 'two_factor_auth')
               ->where('used', false)
               ->update(['used' => true]);

        OtpCode::create([
            'user_id'    => $user->id,
            'code'       => $code,
            'phone'      => $user->phone,
            'purpose'    => 'two_factor_auth',
            'ip_address' => $request->ip(),
            'expires_at' => now()->addSeconds(self::OTP_EXPIRY),
        ]);

        // Send notification (email + SMS)
        $user->notify(new TwoFactorChallengeNotification($code));

        // Store challenge mapping in cache: token → [user_id, fingerprint]
        $challengeToken = Str::random(64);
        Cache::put(
            "2fa_challenge:{$challengeToken}",
            ['user_id' => $user->id, 'device_fingerprint' => $fingerprint],
            self::CHALLENGE_TTL
        );

        return $challengeToken;
    }

    /**
     * Verify a 2FA challenge code.
     */
    public function verifyChallenge(string $token, string $code, Request $request): array
    {
        $data = Cache::get("2fa_challenge:{$token}");

        if (!$data) {
            return ['success' => false, 'message' => 'Challenge expired or invalid. Please log in again.'];
        }

        $user = User::find($data['user_id']);
        if (!$user) {
            return ['success' => false, 'message' => 'Hmm! Nope!.'];
        }

        // Try TOTP first if enabled
        if ($user->hasTwoFactorEnabled() && $user->two_factor_secret) {
            if ($this->verifyTotp($user, $code)) {
                Cache::forget("2fa_challenge:{$token}");
                return [
                    'success'            => true,
                    'user'               => $user,
                    'device_fingerprint' => $data['device_fingerprint'],
                ];
            }
        }

        // Fall back to OTP from DB
        $otp = OtpCode::where('user_id', $user->id)
                      ->where('purpose', 'two_factor_auth')
                      ->valid()
                      ->latest()
                      ->first();

        if (!$otp) {
            return ['success' => false, 'message' => 'No valid OTP found. Please request a new one.'];
        }

        if ($otp->code !== $code) {
            $otp->incrementAttempts();
            $remaining = 3 - $otp->attempts;
            return [
                'success' => false,
                'message' => "Invalid code. {$remaining} attempt(s) remaining.",
            ];
        }

        $otp->markUsed();
        Cache::forget("2fa_challenge:{$token}");

        return [
            'success'            => true,
            'user'               => $user,
            'device_fingerprint' => $data['device_fingerprint'],
        ];
    }

    /**
     * Generate TOTP secret + QR code for setup
     */
    public function generateTotpSecret(User $user): array
    {
        $totp   = TOTP::generate();
        $totp->setLabel($user->email);
        $totp->setIssuer(config('app.name', 'PayYigi'));

        $secret = $totp->getSecret();
        $qrUrl  = $totp->getProvisioningUri();

        // Store secret temporarily until confirmed
        Cache::put("2fa_setup:{$user->id}", $secret, 15 * 60);

        // Generate QR code SVG
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer  = new Writer($renderer);
        $qrCode  = base64_encode($writer->writeString($qrUrl));

        return [
            'secret'  => $secret,
            'qr_url'  => $qrUrl,
            'qr_code' => "data:image/svg+xml;base64,{$qrCode}",
        ];
    }

    /**
     * Enable TOTP after user confirms with code
     */
    public function enableTotp(User $user, string $code): array
    {
        $secret = Cache::get("2fa_setup:{$user->id}");

        if (!$secret) {
            return ['success' => false, 'message' => 'Setup session expired. Please start again.'];
        }

        $totp = TOTP::createFromSecret($secret);

        if (!$totp->verify($code, null, 1)) {
            return ['success' => false, 'message' => 'Invalid code. Please try again.'];
        }

        // Generate recovery codes
        $recoveryCodes = collect(range(1, 8))->map(fn() =>
            strtoupper(Str::random(5) . '-' . Str::random(5))
        )->all();

        $user->update([
            'two_factor_enabled'        => true,
            'two_factor_secret'         => $secret,     // auto-encrypted via cast
            'two_factor_recovery_codes' => $recoveryCodes, // auto-encrypted via cast
        ]);

        Cache::forget("2fa_setup:{$user->id}");

        return ['success' => true, 'recovery_codes' => $recoveryCodes];
    }

    /**
     * Verify a TOTP code
     */
    public function verifyTotp(User $user, string $code): bool
    {
        if (!$user->two_factor_secret) return false;

        $totp = TOTP::createFromSecret($user->two_factor_secret);
        return $totp->verify($code, null, 1); // 1 window = ±30s tolerance
    }
}