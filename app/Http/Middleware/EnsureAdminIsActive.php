<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Spatie\Permission\Models\Role;

class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (!$admin) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$admin->is_active) {
            return response()->json(['message' => 'Admin account is inactive.'], 403);
        }

        return $next($request);
    }
}