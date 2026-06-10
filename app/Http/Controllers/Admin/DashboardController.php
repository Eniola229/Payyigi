<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Get completed transactions for volume calculation
        $completedTransactions = Transaction::where('status', 'completed');
        
        // Calculate total volume (all completed transactions)
        $totalVolume = (clone $completedTransactions)->sum('amount');
        
        // Get pending orders (transactions with pending/processing status)
        $pendingOrders = Transaction::whereIn('status', ['pending', 'awaiting_crypto', 'processing'])->count();
        
        // Get completed orders count
        $completedOrders = Transaction::where('status', 'completed')->count();
        
        // Get pending KYC
        $pendingKYC = User::where(function($query) {
            $query->whereNull('kyc_level')
                  ->orWhere('kyc_level', '!=', 'completed')
                  ->orWhere('kyc_level', 'none');
        })->count();
        
        // Get recent users (last 5)
        $recentUsers = User::latest()
            ->take(5)
            ->get(['id', 'first_name', 'last_name', 'email', 'kyc_level', 'created_at']);
        
        // Get recent transactions (last 5)
        $recentTransactions = Transaction::with('user:id,first_name,last_name,email')
            ->latest()
            ->take(5)
            ->get(['id', 'reference', 'type', 'amount', 'status', 'user_id', 'created_at']);
        
        // Get KYC completed users
        $kycCompletedUsers = User::where('kyc_level', 'completed')
            ->latest()
            ->take(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'kyc_level', 'created_at', 'updated_at']);

        return response()->json([
            'data' => [
                'stats' => [
                    'total_volume'     => (float) $totalVolume,
                    'pending_orders'   => $pendingOrders,
                    'completed_orders' => $completedOrders,
                    'pending_kyc'      => $pendingKYC,
                ],
                
                'recent_users' => $recentUsers->map(function ($user) {
                    return [
                        'id'         => $user->id,
                        'name'       => trim($user->first_name . ' ' . $user->last_name),
                        'email'      => $user->email,
                        'kyc_status' => $user->kyc_level ?? 'pending',
                        'joined_at'  => optional($user->created_at)->toDateTimeString(),
                    ];
                }),
                
                'recent_transactions' => $recentTransactions->map(function ($transaction) {
                    return [
                        'id'         => $transaction->id,
                        'reference'  => $transaction->reference,
                        'type'       => $transaction->type,
                        'amount'     => (float) $transaction->amount,
                        'status'     => $transaction->status,
                        'user'       => $transaction->user
                            ? trim($transaction->user->first_name . ' ' . $transaction->user->last_name)
                            : 'N/A',
                        'created_at' => optional($transaction->created_at)->toDateTimeString(),
                    ];
                }),
                
                'kyc_completed_users' => $kycCompletedUsers->map(function ($user) {
                    return [
                        'id'          => $user->id,
                        'name'        => trim($user->first_name . ' ' . $user->last_name),
                        'email'       => $user->email,
                        'kyc_level'   => $user->kyc_level,
                        'verified_at' => optional($user->updated_at)->toDateTimeString(),
                    ];
                }),
            ],
        ]);
    }
}