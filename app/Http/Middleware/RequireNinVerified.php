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
 
        if (!$user->nin_verified && !$user->bvn_verified) {
            return response()->json([
                'message' => 'Identity verification required. Please verify your NIN or BVN to access this feature.',
                'error'   => 'identity_verification_required',
            ], 403);
        }
 
        return $next($request);
    }
}
