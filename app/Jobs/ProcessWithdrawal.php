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

class ProcessWithdrawal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(private readonly Transaction $transaction) {}

    public function handle(BreetService $breet): void
    {
        $txn = $this->transaction->fresh();

        if (!$txn || $txn->isCompleted() || $txn->isFailed()) {
            return;
        }

        try {
            // Initiate payout via Breet
            // NOTE: Exact Breet payout API endpoint — update to match Breet docs
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.breet.api_key'),
                'Content-Type'  => 'application/json',
            ])->post(config('services.breet.base_url') . '/payouts', [
                'amount'         => $txn->amount,
                'currency'       => 'NGN',
                'bank_code'      => $txn->bank_code,
                'account_number' => $txn->account_number,
                'account_name'   => $txn->account_name,
                'reference'      => $txn->reference,
                'narration'      => "PayYigi withdrawal - {$txn->reference}",
            ]);

            if ($response->successful()) {
                $data = $response->json('data');

                $txn->update([
                    'status'                  => 'completed',
                    'completed_at'            => now(),
                    'bank_transfer_reference' => $data['reference'] ?? null,
                    'breet_reference'         => $data['id']        ?? null,
                ]);

                $txn->user->notify(new \App\Notifications\WithdrawalCompletedNotification($txn));

                AuditLog::record('transaction.withdrawal_completed', [
                    'user_id'        => $txn->user_id,
                    'auditable_type' => Transaction::class,
                    'auditable_id'   => $txn->id,
                ]);

            } else {
                throw new \Exception($response->json('message') ?? 'Payout failed.');
            }

        } catch (\Exception $e) {
            Log::error('ProcessWithdrawal job failed', [
                'transaction_id' => $txn->id,
                'error'          => $e->getMessage(),
                'attempt'        => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                // Final failure — refund wallet
                $txn->update([
                    'status'         => 'failed',
                    'failed_at'      => now(),
                    'failure_reason' => $e->getMessage(),
                ]);

                $txn->wallet->credit((float) $txn->amount);

                $txn->user->notify(new \App\Notifications\WithdrawalFailedNotification($txn));

                AuditLog::record('transaction.withdrawal_failed', [
                    'user_id'        => $txn->user_id,
                    'auditable_type' => Transaction::class,
                    'auditable_id'   => $txn->id,
                    'new_values'     => ['error' => $e->getMessage()],
                ]);
            } else {
                // Retry after delay
                $this->release(now()->addMinutes(2));
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessWithdrawal job permanently failed', [
            'transaction_id' => $this->transaction->id,
            'error'          => $exception->getMessage(),
        ]);
    }
}
