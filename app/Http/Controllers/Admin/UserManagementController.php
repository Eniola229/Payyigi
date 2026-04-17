<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FraudFlag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::withTrashed()
            ->when($request->search, fn($q) =>
                $q->where('email', 'like', "%{$request->search}%")
                  ->orWhere('first_name', 'like', "%{$request->search}%")
                  ->orWhere('last_name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%")
            )
            ->when($request->nin_verified, fn($q) => $q->where('nin_verified', $request->nin_verified === 'true'))
            ->when($request->is_suspended, fn($q) => $q->where('is_suspended', $request->is_suspended === 'true'))
            ->with('wallet')
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $users]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['wallet', 'bankAccounts', 'devices']);

        return response()->json([ 
            'data' => [
                'user'         => $user,
                'stats'        => [
                    'total_transactions' => $user->transactions()->count(),
                    'total_volume'       => $user->transactions()->where('status', 'completed')->sum('amount'),
                    'total_sell'         => $user->transactions()->where('type', 'sell')->where('status', 'completed')->count(),
                    'total_withdraw'     => $user->transactions()->where('type', 'withdraw')->where('status', 'completed')->sum('amount'),
                ],
                'fraud_flags'  => $user->transactions()
                    ->whereHas('fraudFlags')
                    ->count(),
            ],
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorize_permission($request, 'suspend_users');

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($user->is_suspended) {
            return response()->json(['message' => 'User is already suspended.'], 422);
        }

        $user->update([
            'is_suspended'       => true,
            'suspension_reason'  => $request->reason,
            'suspended_at'       => now(),
        ]);

        // Revoke all tokens
        $user->tokens()->delete();

        AuditLog::create([
            'user_id'    => $request->user('admin')->id,
            'event'      => 'admin.user_suspended',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'new_values' => ['reason' => $request->reason],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'User suspended successfully.']);
    }

    public function unsuspend(Request $request, User $user): JsonResponse
    {
        $this->authorize_permission($request, 'unsuspend_users');

        if (!$user->is_suspended) {
            return response()->json(['message' => 'User is not suspended.'], 422);
        }

        $user->update([
            'is_suspended'       => false,
            'suspension_reason'  => null,
            'suspended_at'       => null,
        ]);

        AuditLog::create([
            'user_id'    => $request->user('admin')->id,
            'event'      => 'admin.user_unsuspended',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'User unsuspended successfully.']);
    }

    public function transactions(Request $request, User $user): JsonResponse
    {
        $transactions = $user->transactions()
            ->when($request->type,   fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $transactions]);
    }

    private function authorize_permission(Request $request, string $permission): void
    {
        if (!$request->user('admin')->can($permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}