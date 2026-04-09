<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a minimum KYC level.
 * Usage: ->middleware('kyc:basic') or ->middleware('kyc:advanced')
 */
class RequireKyc
{
    private const LEVELS = ['none' => 0, 'basic' => 1, 'advanced' => 2];

    public function handle(Request $request, Closure $next, string $level = 'basic'): Response
    {
        $user        = $request->user();
        $userLevel   = self::LEVELS[$user->kyc_level]   ?? 0;
        $required    = self::LEVELS[$level]              ?? 1;

        if ($userLevel < $required) {
            return response()->json([
                'message'        => "Please complete {$level} KYC verification to access this feature.",
                'action'         => 'complete_kyc',
                'required_level' => $level,
                'current_level'  => $user->kyc_level,
            ], 403);
        }

        return $next($request);
    }
}
