<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\DeviceService;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
        private readonly DeviceService    $deviceService,
    ) {}
 
    /**
     * Verify 2FA challenge code (OTP sent to phone/email or TOTP)
     * Called after login when new device is detected.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'two_factor_token' => 'required|string',
            'code'             => 'required|string|size:6',
            'trust_device'     => 'boolean',
        ]);

        $result = $this->twoFactorService->verifyChallenge(
            token:  $request->two_factor_token,
            code:   $request->code,
            request: $request,
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        $user        = $result['user'];
        $fingerprint = $result['device_fingerprint'];
        $device      = $this->deviceService->findOrCreateDevice($user, $request, $fingerprint);

        // Trust the device if requested
        if ($request->boolean('trust_device')) {
            $device->trust();
        }

        $device->markUsed();

        // Issue full access token
        $token = $user->createToken(
            name: $device->device_name ?? 'API Token',
            abilities: ['*'],
            expiresAt: now()->addDays(30),
        );

        $token->accessToken->device_fingerprint = $fingerprint;
        $token->accessToken->save();

        $user->update([
            'last_login_ip'     => $request->ip(),
            'last_login_at'     => now(),
            'last_login_device' => $device->device_name,
        ]);

        AuditLog::record('auth.two_factor_passed', ['user_id' => $user->id]);

        return response()->json([
            'message' => '2FA verification successful.',
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => now()->addDays(30)->toISOString(),
            ],
        ]);
    }

    /**
     * Enable 2FA — shows QR code secret for TOTP apps
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => '2FA is already enabled.'], 422);
        }

        $secret = $this->twoFactorService->generateTotpSecret($user);

        return response()->json([
            'message' => 'Scan the QR code with your authenticator app, then confirm with a code.',
            'data'    => [
                'secret'   => $secret['secret'],
                'qr_code'  => $secret['qr_code'],   // base64 SVG
                'qr_url'   => $secret['qr_url'],
            ],
        ]);
    }

    /**
     * Confirm and activate 2FA after scanning QR
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user   = $request->user();
        $result = $this->twoFactorService->enableTotp($user, $request->code);

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        AuditLog::record('auth.two_factor_enabled', ['user_id' => $user->id]);

        return response()->json([
            'message'        => '2FA has been enabled successfully.',
            'recovery_codes' => $result['recovery_codes'],
        ]);
    }

    /**
     * Disable 2FA — requires transaction PIN
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_pin' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (!$user->verifyTransactionPin($request->transaction_pin)) {
            return response()->json(['message' => 'Invalid transaction PIN.'], 422);
        }

        $user->update([
            'two_factor_enabled'       => false,
            'two_factor_secret'        => null,
            'two_factor_recovery_codes'=> null,
        ]);

        AuditLog::record('auth.two_factor_disabled', ['user_id' => $user->id]);

        return response()->json(['message' => '2FA has been disabled.']);
    }
}
