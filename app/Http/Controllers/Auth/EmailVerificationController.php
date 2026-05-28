<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    /**
     * Verify email via signed URL
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        // Remove this line:
        // if (!URL::hasValidSignature($request)) {
        //     return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        // }
        
        // The 'signed' middleware handles it automatically
        
        $user = User::where('id', $id)->firstOrFail();
        
        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }
        
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }
        
        $user->markEmailAsVerified();
        
        return response()->json(['message' => 'Email verified successfully. You can now log in.']);
    }

    /**
     * Resend verification email 
     */ 
    public function resend(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', strtolower($request->email))->first();

        if (!$user) {
            // Don't reveal if email exists
            return response()->json(['message' => 'If that email exists, a verification link has been sent.']);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        // Throttle resend
        $key = 'email_verify_resend|' . $user->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many requests. Try again in {$seconds} seconds.",
            ], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60 * 10);

        $user->notify(new VerifyEmailNotification());

        return response()->json(['message' => 'Verification email sent.']);
    }
}
