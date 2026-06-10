<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AdminManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);
        $perPage = in_array($perPage, [10, 15, 25, 50, 100]) ? $perPage : 15;

        $admins = Admin::with('roles')
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $admins]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => 'required|string|max:50',
            'last_name'  => 'required|string|max:50',
            'email'      => 'required|email|unique:admins,email',
            'password'   => 'required|string|min:8|confirmed',
            'role'       => 'required|string|exists:roles,name',
        ]);

        $admin = Admin::create([
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'email'      => strtolower($request->email),
            'password'   => $request->password,
            'is_active'  => true,
        ]);

        $admin->assignRole($request->role);

        AuditLog::create([
            'user_id'    => $request->user('admin')->id,
            'event'      => 'admin.admin_created',
            'auditable_type' => Admin::class,
            'auditable_id'   => $admin->id,
            'new_values' => ['email' => $admin->email, 'role' => $request->role],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Admin created.', 'data' => $admin->load('roles')], 201);
    }

    public function updateRole(Request $request, Admin $admin): JsonResponse
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $admin->syncRoles([$request->role]);

        AuditLog::create([
            'user_id'    => $request->user('admin')->id,
            'event'      => 'admin.admin_role_updated',
            'auditable_type' => Admin::class,
            'auditable_id'   => $admin->id,
            'new_values' => ['role' => $request->role],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Role updated.']);
    }

    public function toggleActive(Request $request, Admin $admin): JsonResponse
    {
        // Prevent super admin from deactivating themselves 
        if ($admin->id === $request->user('admin')->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $admin->update(['is_active' => !$admin->is_active]);

        AuditLog::create([
            'user_id'    => $request->user('admin')->id,
            'event'      => 'admin.admin_toggled',
            'auditable_type' => Admin::class,
            'auditable_id'   => $admin->id,
            'new_values' => ['is_active' => $admin->is_active],
            'ip_address' => $request->ip(),
        ]);

        $status = $admin->is_active ? 'activated' : 'deactivated';
        return response()->json(['message' => "Admin {$status}."]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::where('guard_name', 'admin')->get(['id', 'name']),
        ]);
    }
}