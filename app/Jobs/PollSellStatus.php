<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\LocalRamp\LocalRampService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PollSellStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 20;  // poll up to 20 times
    public int $timeout = 30;

    public function __construct(private readonly Transaction $transaction) {}

    public function handle(LocalRampService $localRamp): void
    {
        $txn = $this->transaction->fresh();

        if (!$txn || $txn->isCompleted() || $txn->isFailed()) return;

        try {
            $statusData = $localRamp->getSellStatus($txn->reference);
            $status     = $statusData['status'] ?? 'pending';

            if ($status === 'completed') {
                // Credit user wallet
                $balanceBefore = (float) $txn->wallet->balance;
                $txn->wallet->credit((float) $txn->amount);

                $txn->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $txn->wallet->fresh()->balance,
                ]);

                $txn->user->notify(new \App\Notifications\TransactionCompletedNotification($txn));

                Log::info('Sell completed', ['reference' => $txn->reference]);

            } elseif ($status === 'failed') {
                $txn->update([
                    'status'         => 'failed',
                    'failed_at'      => now(),
                    'failure_reason' => 'Transaction failed on LocalRamp.',
                ]);

                $txn->user->notify(new \App\Notifications\TransactionFailedNotification($txn));

            } else {
                // Still pending — retry after 30 seconds
                $this->release(30);
            }

        } catch (\Exception $e) {
            Log::error('PollSellStatus failed', [
                'reference' => $txn->reference,
                'error'     => $e->getMessage(),
            ]);
            $this->release(30);
        }
    }
}