<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSupportSession
{
    private const MAX_AGE = 4 * 60 * 60; // 4 hours

    public function handle(Request $request, Closure $next)
    {
        $email = $request->session()->get('support_email');
        $at    = (int) $request->session()->get('support_verified_at', 0);

        if (!$email || (time() - $at) > self::MAX_AGE) {
            $request->session()->forget(['support_email', 'support_verified_at']);

            return $request->expectsJson()
                ? response()->json(['message' => 'Your support session has expired.'], 401)
                : redirect()->route('support.landing', ['expired' => 1]);
        }

        return $next($request);
    }
}
