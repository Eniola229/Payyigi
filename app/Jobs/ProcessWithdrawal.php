<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Korapay\KorapayPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWithdrawal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;
    public int $backoff = 30; // seconds between retries

    public function __construct(private readonly Transaction $transaction) {}

    public function handle(KorapayPayoutService $korapay): void
    {
        $txn = $this->transaction->fresh();

        if (!$txn || $txn->isCompleted() || $txn->isFailed()) return;

        try {
            $data = $korapay->disburse(
                reference:     $txn->reference,
                amount:        (float) $txn->amount,
                bankCode:      $txn->bank_code,
                accountNumber: $txn->account_number,
                accountName:   $txn->account_name,
                customerEmail: $txn->user->email,
                narration:     "PayYigi Withdrawal - {$txn->reference}",
            );

            // Korapay returns status: "processing" immediately
            // Completion confirmed via webhook (transfer.success / transfer.failed)
            $txn->update([
                'status'                  => 'processing',
                'bank_transfer_reference' => $data['reference'] ?? null,
                'provider_response'          => $data,
            ]);

            Log::info('Korapay withdrawal initiated', [
                'reference'       => $txn->reference,
                'korapay_ref'     => $data['reference'] ?? null,
            ]);

        } catch (\RuntimeException $e) {
            // Server error (5xx) — verify before acting
            $message = $e->getMessage();

            if (str_starts_with($message, 'server_error:')) {
                $this->verifyBeforeActing($korapay, $txn);
                return;
            }

            $this->handleFailure($txn, $message);

        } catch (\Exception $e) {
            Log::error('ProcessWithdrawal failed', [
                'reference' => $txn->reference,
                'error'     => $e->getMessage(),
                'attempt'   => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->handleFailure($txn, $e->getMessage());
            } else {
                $this->release($this->backoff);
            }
        }
    }

    /**
     * On 5xx errors, verify payout status before retrying or refunding.
     * Korapay docs: never treat 5xx as failed without verifying first.
     */
    private function verifyBeforeActing(KorapayPayoutService $korapay, Transaction $txn): void
    {
        try {
            $status = $korapay->getPayoutStatus($txn->reference);
            $state  = $status['status'] ?? null;

            if ($state === 'success') {
                // Already processed — mark completed, don't retry
                $txn->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                ]);
                $txn->user->notify(new \App\Notifications\WithdrawalCompletedNotification($txn));
            } elseif ($state === 'failed') {
                $this->handleFailure($txn, 'Payout failed on Korapay.');
            } else {
                // Still processing — release for retry
                $this->release($this->backoff);
            }
        } catch (\Exception $e) {
            // Can't verify — retry
            $this->release($this->backoff);
        }
    }

    /**
     * Final failure — refund wallet and notify user.
     */
    private function handleFailure(Transaction $txn, string $reason): void
    {
        $txn->update([
            'status'         => 'failed',
            'failed_at'      => now(),
            'failure_reason' => $reason,
        ]);

        // Refund wallet
        $txn->wallet->credit((float) $txn->amount);

        $txn->user->notify(new \App\Notifications\WithdrawalFailedNotification($txn));

        AuditLog::record('transaction.withdrawal_failed', [
            'user_id'        => $txn->user_id,
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'new_values'     => ['error' => $reason],
        ]);

        Log::error('Withdrawal permanently failed — wallet refunded', [
            'reference' => $txn->reference,
            'reason'    => $reason,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $txn = $this->transaction->fresh();
        if ($txn) $this->handleFailure($txn, $e->getMessage());
    }
}