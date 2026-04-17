<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AdminLoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $key = 'admin_login|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        $admin = Admin::where('email', strtolower($request->email))->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            RateLimiter::hit($key, 60 * 15);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (!$admin->is_active) {
            return response()->json(['message' => 'Account is inactive.'], 403);
        }

        RateLimiter::clear($key);

        $token = $admin->createToken('admin-token', ['*'], now()->addHours(8));

        $admin->update([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ]);

        AuditLog::create([
            'user_id'    => $admin->id,
            'event'      => 'admin.login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'data'    => [
                'token'       => $token->plainTextToken,
                'expires_at'  => now()->addHours(8)->toISOString(),
                'admin'       => [
                    'id'          => $admin->id,
                    'name'        => $admin->full_name,
                    'email'       => $admin->email,
                    'roles'       => $admin->getRoleNames(),
                    'permissions' => $admin->getAllPermissions()->pluck('name'),
                ],
            ],
        ]);
    }
}