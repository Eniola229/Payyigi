<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require NIN verification before accessing certain routes.
 */
class RequireNinVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user->nin_verified) {
            return response()->json([
                'message' => 'Please verify your NIN to access this feature.',
                'action'  => 'verify_nin',
            ], 403);
        }

        return $next($request);
    }
}
