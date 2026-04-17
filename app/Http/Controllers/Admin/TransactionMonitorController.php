<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FraudFlag;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionMonitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::with(['user:id,first_name,last_name,email,phone'])
            ->when($request->type,       fn($q) => $q->where('type', $request->type))
            ->when($request->status,     fn($q) => $q->where('status', $request->status))
            ->when($request->asset,      fn($q) => $q->where('crypto_asset', $request->asset))
            ->when($request->date_from,  fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to,    fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->min_amount, fn($q) => $q->where('amount', '>=', $request->min_amount))
            ->when($request->search,     fn($q) =>
                $q->where('reference', 'like', "%{$request->search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('email', 'like', "%{$request->search}%")
                  )
            )
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $transactions]);
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load(['user:id,first_name,last_name,email,phone,nin_verified', 'wallet', 'bankAccount']);

        return response()->json(['data' => $transaction]);
    }

    public function flag(Request $request, Transaction $transaction): JsonResponse
    {
        if (!$request->user('admin')->can('flag_transactions')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'type'     => 'required|string|in:suspicious_transaction,unusual_volume,multiple_failed_pins,account_takeover_suspected,duplicate_nin,blacklisted_address,manual_flag',
            'severity' => 'required|string|in:low,medium,high,critical',
            'reason'   => 'required|string|max:1000',
        ]);

        $flag = FraudFlag::create([
            'flagged_by'     => $request->user('admin')->id,
            'user_id'        => $transaction->user_id,
            'transaction_id' => $transaction->id,
            'type'           => $request->type,
            'severity'       => $request->severity,
            'reason'         => $request->reason,
            'status'         => 'open',
        ]);

        AuditLog::create([
            'user_id'        => $request->user('admin')->id,
            'event'          => 'admin.transaction_flagged',
            'auditable_type' => Transaction::class,
            'auditable_id'   => $transaction->id,
            'new_values'     => ['severity' => $request->severity, 'reason' => $request->reason],
            'ip_address'     => $request->ip(),
        ]);

        return response()->json(['message' => 'Transaction flagged.', 'data' => $flag], 201);
    }

    // Pending withdrawals that need attention
    public function pendingWithdrawals(Request $request): JsonResponse
    {
        $withdrawals = Transaction::with(['user:id,first_name,last_name,email,phone'])
            ->where('type', 'withdraw')
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $withdrawals]);
    }

    // Revenue report
    public function revenue(Request $request): JsonResponse
    {
        if (!$request->user('admin')->can('view_revenue')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $transactions = Transaction::where('status', 'completed')
            ->whereBetween('completed_at', [$request->from, $request->to])
            ->selectRaw('
                type,
                count(*) as count,
                sum(amount) as total_volume,
                sum(spread_amount) as total_spread,
                sum(fee) as total_platform_fee,
                sum(breet_fee) as total_breet_fee
            ')
            ->groupBy('type')
            ->get();

        $totals = Transaction::where('status', 'completed')
            ->whereBetween('completed_at', [$request->from, $request->to])
            ->selectRaw('
                sum(amount) as total_volume,
                sum(spread_amount) as total_spread,
                sum(fee) as total_platform_fee,
                sum(breet_fee) as total_breet_fee
            ')
            ->first();

        return response()->json([
            'data' => [
                'period'     => ['from' => $request->from, 'to' => $request->to],
                'by_type'    => $transactions,
                'totals'     => $totals,
            ],
        ]);
    }
}