<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordController extends Controller
{
    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Throttle
        $key = 'forgot_password|' . $request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many requests. Please wait before trying again.'], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60 * 10);

        $status = Password::sendResetLink($request->only('email'));

        // Always return success to avoid email enumeration
        return response()->json(['message' => 'If that email exists, a password reset link has been sent.']);
    }

    /**
     * Reset password via token
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => $password, // mutator hashes it
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all tokens on password reset
                $user->tokens()->delete();

                event(new PasswordReset($user));
                AuditLog::record('auth.password_reset', ['user_id' => $user->id]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        return response()->json(['message' => 'Password has been reset successfully. Please log in.']);
    }

    /**
     * Change password (authenticated)
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => $request->password]);

        // Revoke all other tokens for security
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        AuditLog::record('auth.password_changed', ['user_id' => $user->id]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Set transaction PIN
     */
    public function setTransactionPin(Request $request): JsonResponse
    {
        $request->validate([
            'pin'              => 'required|string|size:6|regex:/^\d{6}$/',
            'pin_confirmation' => 'required|same:pin',
        ]);

        $user = $request->user();

        if ($user->transaction_pin) {
            return response()->json([
                'message' => 'Transaction PIN already set. Use the change PIN endpoint.',
            ], 422);
        }

        $user->update(['transaction_pin' => $request->pin]); // mutator hashes

        AuditLog::record('auth.transaction_pin_set', ['user_id' => $user->id]);

        return response()->json(['message' => 'Transaction PIN set successfully.']);
    }

    /**
     * Change transaction PIN
     */
    public function changeTransactionPin(Request $request): JsonResponse
    {
        $request->validate([
            'current_pin' => 'required|string|size:6',
            'pin'         => 'required|string|size:6|regex:/^\d{6}$/|confirmed',
        ]);

        $user = $request->user();

        if (!$user->verifyTransactionPin($request->current_pin)) {
            return response()->json(['message' => 'Current PIN is incorrect.'], 422);
        }

        $user->update(['transaction_pin' => $request->pin]);

        AuditLog::record('auth.transaction_pin_changed', ['user_id' => $user->id]);

        return response()->json(['message' => 'Transaction PIN changed successfully.']);
    }
}
