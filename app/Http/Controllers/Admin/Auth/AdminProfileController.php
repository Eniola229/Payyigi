<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $admin = $request->user('admin');

        return response()->json([
            'data' => [
                'id'            => $admin->id,
                'name'          => $admin->full_name,
                'email'         => $admin->email,
                'is_active'     => $admin->is_active,
                'last_login_at' => $admin->last_login_at,
                'last_login_ip' => $admin->last_login_ip,
                'roles'         => $admin->getRoleNames(),
                'permissions'   => $admin->getAllPermissions()->pluck('name'),
                'created_at'    => $admin->created_at,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => 'sometimes|string|min:2|max:50',
            'last_name'  => 'sometimes|string|min:2|max:50',
        ]);

        $admin = $request->user('admin');
        $admin->update($request->only('first_name', 'last_name'));

        AuditLog::create([
            'user_id'    => $admin->id,
            'event'      => 'admin.profile.updated',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Profile updated.',
            'data'    => $admin->fresh(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $admin = $request->user('admin');

        if (!Hash::check($request->current_password, $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $admin->update([
            'password' => Hash::make($request->password),
        ]);

        // Revoke all other tokens so other sessions are kicked out
        $admin->tokens()->where('id', '!=', $request->user('admin')->currentAccessToken()->id)->delete();

        AuditLog::create([
            'user_id'    => $admin->id,
            'event'      => 'admin.password.changed',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Password changed. Other sessions have been revoked.']);
    }
}