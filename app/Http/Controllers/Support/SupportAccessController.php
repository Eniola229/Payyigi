<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportAccessToken;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupportAccessController extends Controller
{
    /** API: POST /api/support/request  { "email": "..." } — always returns the same response. */
    public function request(Request $request, SupportMailer $mailer)
    {
        $email = strtolower(trim($request->validate(['email' => 'required|email:rfc|max:255'])['email']));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user || SupportTicket::where('email', $email)->exists()) {
            SupportAccessToken::where('expires_at', '<', now())->delete();

            $token = Str::random(64);
            SupportAccessToken::create([
                'email'      => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(20),
            ]);

            $base = rtrim(config('services.support.portal_url') ?: config('app.url'), '/');
            $mailer->sendAccessLink($email, "{$base}/support/verify/{$token}", $user?->first_name);
        }

        // Identical message whether or not the email exists (no account enumeration).
        return response()->json([
            'message' => "If this email belongs to a PayYigi account or an existing support ticket, we've sent a secure link to it. Check your inbox and spam folder.",
        ]);
    }

    public function landing(Request $request)
    {
        if ($request->session()->has('support_email') && !$request->boolean('expired')) {
            return redirect()->route('support.portal');
        }

        return view('support.request', ['expired' => $request->boolean('expired')]);
    }

    /** Web: the link from the email. */
    public function verify(Request $request, string $token)
    {
        $row = SupportAccessToken::where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return redirect()->route('support.landing', ['expired' => 1]);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'support_email'       => $row->email,
            'support_verified_at' => time(),
        ]);

        return redirect()->route('support.portal');
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['support_email', 'support_verified_at']);

        return response()->json(['message' => 'Signed out.']);
    }
}
