<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorPayoutStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 15;
    public int $timeout = 30;

    public function __construct(private readonly Transaction $transaction) {}

    public function handle(BreetService $breet): void
    {
        $txn = $this->transaction->fresh();
        
        if (!$txn || $txn->status !== 'completed') {
            return;
        }

        if (empty($txn->provider_payout_id)) {
            Log::warning('MonitorPayoutStatus: no payout ID found', [
                'reference' => $txn->reference,
            ]);
            return;
        }

        try {
            $payoutData = $breet->getWithdrawalStatus($txn->provider_payout_id);
            $status = $payoutData['status'] ?? 'pending';
            $payoutStatus = $payoutData['payoutStatus'] ?? $status;

            // Check final statuses
            if (in_array($payoutStatus, ['success', 'completed', 'settled'])) {
                $txn->update([
                    'provider_payout_status' => 'completed',
                    'payout_completed_at' => now(),
                    'metadata' => array_merge((array) ($txn->metadata ?? []), [
                        'payout_completed_at' => now()->toISOString(),
                    ]),
                ]);

                AuditLog::record('transaction.payout_completed', [
                    'user_id' => $txn->user_id,
                    'auditable_type' => Transaction::class,
                    'auditable_id' => $txn->id,
                    'new_values' => [
                        'reference' => $txn->reference,
                        'payout_id' => $txn->provider_payout_id,
                    ],
                ]);

                $txn->user->notify(new \App\Notifications\PayoutCompletedNotification($txn));
                return;
            }

            // Check for failure statuses
            if (in_array($payoutStatus, ['failed', 'reversed', 'cancelled'])) {
                $txn->update([
                    'provider_payout_status' => 'failed',
                    'failure_reason' => $payoutData['message'] ?? 'Payout failed on Breet',
                    'metadata' => array_merge((array) ($txn->metadata ?? []), [
                        'payout_failed_at' => now()->toISOString(),
                    ]),
                ]);

                AuditLog::record('transaction.payout_failed', [
                    'user_id' => $txn->user_id,
                    'auditable_type' => Transaction::class,
                    'auditable_id' => $txn->id,
                    'new_values' => [
                        'reference' => $txn->reference,
                        'payout_id' => $txn->provider_payout_id,
                        'reason' => $payoutData['message'] ?? 'Unknown',
                    ],
                ]);

                $txn->user->notify(new \App\Notifications\PayoutFailedNotification($txn));
                return;
            }

            // Still processing - retry with exponential backoff
            $attempts = $this->attempts();
            $delay = min(300, 30 * pow(2, $attempts - 1));
            
            Log::info('Payout still processing, will retry', [
                'reference' => $txn->reference,
                'attempt' => $attempts,
                'delay' => $delay,
                'status' => $payoutStatus,
            ]);

            $this->release($delay);

        } catch (\Exception $e) {
            Log::error('Error monitoring payout', [
                'reference' => $txn->reference,
                'error' => $e->getMessage(),
            ]);
            
            if ($this->attempts() < $this->tries) {
                $delay = min(300, 30 * pow(2, $this->attempts()));
                $this->release($delay);
            }
        }
    }
}