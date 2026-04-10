<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WithdrawController extends Controller
{
    public function __construct(private readonly WalletService $walletService) {}

    /**
     * Initiate a withdrawal — deducts from wallet, dispatches payout job
     *
     * POST /api/v1/withdraw
     * Middleware: auth:sanctum, account.active, nin.verified, kyc:basic, txn.pin
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'amount'          => 'required|numeric|min:100|max:10000000',
            'bank_account_id' => 'required|uuid|exists:bank_accounts,id',
        ]);

        $user        = $request->user();
        $amount      = (float) $request->amount;
        $wallet      = $user->wallet;
        $bankAccount = $user->bankAccounts()->findOrFail($request->bank_account_id);

        if (!$bankAccount->is_verified) {
            return response()->json(['message' => 'Bank account is not verified.'], 422);
        }

        // Check available balance
        if (!$wallet->hasSufficientBalance($amount)) {
            return response()->json([
                'message'           => 'Insufficient balance.',
                'available_balance' => $wallet->getAvailableBalance(),
            ], 422);
        }

        // Check daily withdrawal limit
        if ($this->walletService->hasExceededDailyLimit(
            $user->id,
            $amount,
            $user->dailyWithdrawalLimit()
        )) {
            return response()->json([
                'message' => 'This withdrawal would exceed your daily limit. Please complete Advanced KYC to increase your limit.',
                'limit'   => number_format($user->dailyWithdrawalLimit() / 100),
            ], 422);
        }

        try {
            $transaction = DB::transaction(function () use ($user, $wallet, $amount, $bankAccount, $request) {
                // Deduct from wallet immediately
                $balanceBefore = (float) $wallet->balance;
                $wallet->debit($amount);

                $transaction = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $wallet->id,
                    'type'           => 'withdraw',
                    'entry_type'     => 'debit',
                    'currency'       => 'NGN',
                    'amount'         => $amount,
                    'fee'            => 0,
                    'net_amount'     => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $wallet->fresh()->balance,
                    'bank_account_id'=> $bankAccount->id,
                    'bank_name'      => $bankAccount->bank_name,
                    'bank_code'      => $bankAccount->bank_code,
                    'account_number' => $bankAccount->account_number,
                    'account_name'   => $bankAccount->account_name,
                    'status'         => 'processing',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                ]);

                // Dispatch background job to process the payout via Breet
                \App\Jobs\ProcessWithdrawal::dispatch($transaction);

                return $transaction;
            });

            AuditLog::record('transaction.withdrawal_initiated', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'amount'    => $amount,
                    'bank'      => $bankAccount->account_number,
                    'reference' => $transaction->reference,
                ],
            ]);

            return response()->json([
                'message' => 'Withdrawal initiated. Funds will be sent to your bank account shortly.',
                'data'    => [
                    'reference'      => $transaction->reference,
                    'amount'         => $transaction->amount,
                    'bank_name'      => $transaction->bank_name,
                    'account_number' => $transaction->account_number,
                    'account_name'   => $transaction->account_name,
                    'status'         => $transaction->status,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Withdrawal history
     * GET /api/v1/withdraw/history
     */
    public function history(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->transactions()
            ->where('type', 'withdraw')
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $transactions]);
    }
}
