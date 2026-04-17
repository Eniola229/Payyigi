<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\FraudFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $today     = now()->startOfDay();
        $thisWeek  = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        return response()->json([
            'data' => [

                // ── Users ─────────────────────────────────────────────────────
                'users' => [
                    'total'           => User::count(),
                    'verified_today'  => User::whereDate('created_at', today())->count(),
                    'nin_verified'    => User::where('nin_verified', true)->count(),
                    'suspended'       => User::where('is_suspended', true)->count(),
                ],

                // ── Transactions today ────────────────────────────────────────
                'transactions_today' => [
                    'total'     => Transaction::whereDate('created_at', today())->count(),
                    'completed' => Transaction::whereDate('created_at', today())->where('status', 'completed')->count(),
                    'pending'   => Transaction::whereDate('created_at', today())->whereIn('status', ['pending', 'awaiting_crypto', 'processing'])->count(),
                    'failed'    => Transaction::whereDate('created_at', today())->where('status', 'failed')->count(),
                ],

                // ── Volume ────────────────────────────────────────────────────
                'volume' => [
                    'today'      => Transaction::whereDate('completed_at', today())->where('status', 'completed')->sum('amount'),
                    'this_week'  => Transaction::where('completed_at', '>=', $thisWeek)->where('status', 'completed')->sum('amount'),
                    'this_month' => Transaction::where('completed_at', '>=', $thisMonth)->where('status', 'completed')->sum('amount'),
                ],

                // ── Revenue (spread earnings) ──────────────────────────────
                'revenue' => [
                    'today'      => Transaction::whereDate('completed_at', today())->where('status', 'completed')->sum('spread_amount'),
                    'this_week'  => Transaction::where('completed_at', '>=', $thisWeek)->where('status', 'completed')->sum('spread_amount'),
                    'this_month' => Transaction::where('completed_at', '>=', $thisMonth)->where('status', 'completed')->sum('spread_amount'),
                ],

                // ── By type ───────────────────────────────────────────────────
                'by_type' => Transaction::where('status', 'completed')
                    ->whereDate('created_at', today())
                    ->select('type', DB::raw('count(*) as count'), DB::raw('sum(amount) as volume'))
                    ->groupBy('type')
                    ->get(),

                // ── Fraud ─────────────────────────────────────────────────────
                'fraud' => [
                    'open_flags'    => FraudFlag::where('status', 'open')->count(),
                    'critical'      => FraudFlag::where('status', 'open')->where('severity', 'critical')->count(),
                    'resolved_today'=> FraudFlag::whereDate('resolved_at', today())->count(),
                ],

            ],
        ]);
    }
}