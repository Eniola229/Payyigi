<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): \Illuminate\Http\Response
    {
        $user = User::where('id', $id)->firstOrFail();

        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            $title      = 'Verification Failed';
            $message    = "This verification link is invalid or has expired.\n\nPlease request a new verification email and try again.";
            $button_url  = 'https://payyigi.com/login';
            $button_text = 'Go to Login';

            return response(view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render());
        }

        if ($user->hasVerifiedEmail()) {
            $title      = 'Already Verified';
            $message    = "Your email has already been verified.\n\nYou can go ahead and log in to your PayYigi account.";
            $button_url  = 'https://payyigi.com/login';
            $button_text = 'Go to Login';

            return response(view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render());
        }

        $user->markEmailAsVerified();

        $title      = 'Email Verified!';
        $message    = "Hello {$user->first_name}!\n\nYour email has been verified successfully. Welcome to PayYigi!\n\nYou can now log in and start trading.";
        $button_url  = 'https://payyigi.com/login';
        $button_text = 'Go to Login';

        return response(view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render());
    }

    public function resend(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', strtolower($request->email))->first();

        if (!$user) {
            return response()->json(['message' => 'If that email exists, a verification link has been sent.']);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $key = 'email_verify_resend|' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many requests. Try again in {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($key, 60 * 10);

        $user->notify(new VerifyEmailNotification());

        return response()->json(['message' => 'Verification email sent.']);
    }
}