<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Credit a wallet and record the transaction entry.
     * Always call inside a DB::transaction().
     */
    public function credit(
        Wallet      $wallet,
        float       $amount,
        Transaction $transaction,
    ): void {
        $balanceBefore = (float) $wallet->balance;

        $wallet->credit($amount);

        $transaction->update([
            'balance_before' => $balanceBefore,
            'balance_after'  => $wallet->fresh()->balance,
        ]);
    }

    /**
     * Debit a wallet and record the transaction entry.
     * Always call inside a DB::transaction().
     */
    public function debit(
        Wallet      $wallet,
        float       $amount,
        Transaction $transaction,
    ): void {
        if (!$wallet->hasSufficientBalance($amount)) {
            throw new \Exception('Insufficient wallet balance.');
        }

        $balanceBefore = (float) $wallet->balance;

        $wallet->debit($amount);

        $transaction->update([
            'balance_before' => $balanceBefore,
            'balance_after'  => $wallet->fresh()->balance,
        ]);
    }

    /**
     * Get wallet balance summary for a user
     */
    public function getSummary(Wallet $wallet): array
    {
        return [
            'currency'          => $wallet->currency,
            'balance'           => (float) $wallet->balance,
            'locked_balance'    => (float) $wallet->locked_balance,
            'available_balance' => $wallet->getAvailableBalance(),
        ];
    }

    /**
     * Check daily withdrawal total for a user
     */
    public function getTodayWithdrawalTotal(string $userId): float
    {
        return (float) Transaction::where('user_id', $userId)
            ->where('type', 'withdraw')
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->sum('amount');
    }

    /**
     * Check if user has exceeded daily withdrawal limit
     */
    public function hasExceededDailyLimit(string $userId, float $amount, int $limitKobo): bool
    {
        $todayTotal = $this->getTodayWithdrawalTotal($userId);
        $limit      = $limitKobo / 100; // convert kobo to naira

        return ($todayTotal + $amount) > $limit;
    }
}
