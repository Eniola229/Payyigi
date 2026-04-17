<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FraudFlag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FraudController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $flags = FraudFlag::with([
                'user:id,first_name,last_name,email,is_suspended',
                'transaction:id,reference,type,amount,status',
                'flaggedBy:id,first_name,last_name',
            ])
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->severity, fn($q) => $q->where('severity', $request->severity))
            ->when($request->type,     fn($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $flags]);
    }

    public function show(FraudFlag $fraudFlag): JsonResponse
    {
        $fraudFlag->load([
            'user.wallet', 'user.bankAccounts',
            'transaction', 'flaggedBy', 'resolvedBy',
        ]);

        return response()->json(['data' => $fraudFlag]);
    }

    public function resolve(Request $request, FraudFlag $fraudFlag): JsonResponse
    {
        if (!$request->user('admin')->can('resolve_fraud_flags')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'status' => 'required|in:resolved,false_positive,investigating',
            'notes'  => 'required|string|max:1000',
        ]);

        $fraudFlag->update([
            'status'      => $request->status,
            'notes'       => $request->notes,
            'resolved_by' => $request->user('admin')->id,
            'resolved_at' => in_array($request->status, ['resolved', 'false_positive']) ? now() : null,
        ]);

        AuditLog::create([
            'user_id'        => $request->user('admin')->id,
            'event'          => 'admin.fraud_flag_resolved',
            'auditable_type' => FraudFlag::class,
            'auditable_id'   => $fraudFlag->id,
            'new_values'     => ['status' => $request->status, 'notes' => $request->notes],
            'ip_address'     => $request->ip(),
        ]);

        return response()->json(['message' => 'Flag updated.', 'data' => $fraudFlag->fresh()]);
    }

    // Flag a user directly (not tied to a transaction)
    public function flagUser(Request $request, User $user): JsonResponse
    {
        if (!$request->user('admin')->can('create_fraud_flags')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'type'     => 'required|string|in:suspicious_transaction,unusual_volume,multiple_failed_pins,account_takeover_suspected,duplicate_nin,blacklisted_address,manual_flag',
            'severity' => 'required|string|in:low,medium,high,critical',
            'reason'   => 'required|string|max:1000',
        ]);

        $flag = FraudFlag::create([
            'flagged_by' => $request->user('admin')->id,
            'user_id'    => $user->id,
            'type'       => $request->type,
            'severity'   => $request->severity,
            'reason'     => $request->reason,
            'status'     => 'open',
        ]);

        AuditLog::create([
            'user_id'        => $request->user('admin')->id,
            'event'          => 'admin.user_flagged',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'new_values'     => ['severity' => $request->severity, 'reason' => $request->reason],
            'ip_address'     => $request->ip(),
        ]);

        return response()->json(['message' => 'User flagged.', 'data' => $flag], 201);
    }
}