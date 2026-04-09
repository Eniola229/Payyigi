<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    // Minimum withdrawal amount in NGN
    private const MIN_WITHDRAWAL = 500.00;

    // Maximum single withdrawal in NGN (adjusts by KYC level)
    private const MAX_WITHDRAWAL_NO_NIN = 500000.00;

    public function __construct(private readonly BreetService $breet) {}

    /**
     * Initiate an automatic withdrawal.
     * Deducts from wallet instantly → sends to bank via Breet.
     *
     * POST /withdrawals
     * body: { amount, bank_account_id, transaction_pin }
     *
     * RequireTransactionPin middleware validates the PIN before this runs.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate([
            'amount'          => 'required|numeric|min:' . self::MIN_WITHDRAWAL,
            'bank_account_id' => 'required|uuid|exists:bank_accounts,id',
        ]);

        $user       = $request->user();
        $amount     = (float) $request->amount;
        $wallet     = $user->wallet;

        // ── Guards ────────────────────────────────────────────────────────────

        if (!$user->canWithdraw()) {
            return response()->json([
                'message' => 'You must verify your NIN and email before withdrawing.',
            ], 403);
        }

        if (!$wallet->hasSufficientBalance($amount)) {
            return response()->json([
                'message' => 'Insufficient wallet balance.',
                'balance' => $wallet->getAvailableBalance(),
            ], 422);
        }

        // Daily withdrawal limit check
        $todayWithdrawals = $user->transactions()
            ->where('type', 'withdraw')
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->whereDate('created_at', today())
            ->sum('amount');

        $dailyLimit = $user->dailyWithdrawalLimit();

        if (($todayWithdrawals + $amount) > $dailyLimit) {
            $remaining = max(0, $dailyLimit - $todayWithdrawals);
            return response()->json([
                'message'            => 'Daily withdrawal limit exceeded.',
                'daily_limit'        => $dailyLimit,
                'used_today'         => $todayWithdrawals,
                'remaining_today'    => $remaining,
            ], 422);
        }

        // Confirm bank account belongs to user
        $bankAccount = BankAccount::where('id', $request->bank_account_id)
                                  ->where('user_id', $user->id)
                                  ->first();

        if (!$bankAccount) {
            return response()->json(['message' => 'Bank account not found.'], 404);
        }

        // ── Process ───────────────────────────────────────────────────────────

        return DB::transaction(function () use ($user, $wallet, $amount, $bankAccount, $request) {
            $balanceBefore = (float) $wallet->balance;

            // Debit wallet immediately
            $wallet->debit($amount);

            $balanceAfter = (float) $wallet->fresh()->balance;

            // Create transaction record
            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'wallet_id'       => $wallet->id,
                'type'            => 'withdraw',
                'entry_type'      => 'debit',
                'currency'        => 'NGN',
                'amount'          => $amount,
                'fee'             => 0,
                'breet_fee'       => 0,
                'net_amount'      => $amount,
                'balance_before'  => $balanceBefore,
                'balance_after'   => $balanceAfter,
                'bank_account_id' => $bankAccount->id,
                'bank_name'       => $bankAccount->bank_name,
                'bank_code'       => $bankAccount->bank_code,
                'account_number'  => $bankAccount->account_number,
                'account_name'    => $bankAccount->account_name,
                'status'          => 'processing',
                'session_id'      => session()->getId(),
                'ip_address'      => $request->ip(),
                'user_agent'      => $request->userAgent(),
            ]);

            // Send to bank via Breet instantly
            try {
                $payoutResult = $this->breet->initiateWithdrawal(
                    amount:      $amount,
                    reference:   $transaction->reference,
                    bankAccount: [
                        'account_number' => $bankAccount->account_number,
                        'bank_code'      => $bankAccount->bank_code,
                        'account_name'   => $bankAccount->account_name,
                    ],
                );

                $transaction->update([
                    'breet_reference' => $payoutResult['reference'] ?? null,
                    'breet_response'  => $payoutResult,
                    // Status stays 'processing' until payout webhook confirms
                ]);

            } catch (\Exception $e) {
                // Breet call failed — reverse the debit
                $wallet->credit($amount);

                $transaction->update([
                    'status'         => 'failed',
                    'failure_reason' => $e->getMessage(),
                    'failed_at'      => now(),
                    'balance_after'  => $wallet->fresh()->balance,
                ]);

                Log::error('Withdrawal payout failed', [
                    'transaction' => $transaction->reference,
                    'error'       => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Withdrawal could not be processed at this time. Please try again.',
                ], 500);
            }

            AuditLog::record('transaction.withdrawal_initiated', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'reference'      => $transaction->reference,
                    'amount'         => $amount,
                    'account_last4'  => substr($bankAccount->account_number, -4),
                ],
            ]);

            return response()->json([
                'message' => 'Withdrawal initiated. Your money is on its way.',
                'data'    => [
                    'transaction_id' => $transaction->id,
                    'reference'      => $transaction->reference,
                    'amount'         => $amount,
                    'currency'       => 'NGN',
                    'status'         => $transaction->status,
                    'bank'           => [
                        'bank_name'      => $bankAccount->bank_name,
                        'account_number' => $bankAccount->account_number,
                        'account_name'   => $bankAccount->account_name,
                    ],
                    'estimated_arrival' => '5 minutes',
                ],
            ], 201);
        });
    }

    /**
     * Get withdrawal history.
     */
    public function history(Request $request): JsonResponse
    {
        $withdrawals = $request->user()
            ->transactions()
            ->where('type', 'withdraw')
            ->latest()
            ->paginate(15);

        return response()->json(['data' => $withdrawals]);
    }
}
