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

class PollSellStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 20;
    public int $timeout = 30;

    public function __construct(private readonly Transaction $transaction) {}

    public function handle(BreetService $breet): void
    {
        $txn = $this->transaction->fresh();
        if (!$txn || $txn->isCompleted() || $txn->isFailed()) return;

        if (!$txn->provider_order_id) {
            Log::warning('PollSellStatus: no provider_order_id', ['reference' => $txn->reference]);
            return;
        }

        try {
            // provider_order_id = Breet wallet ID.
            // We poll the WALLET to see if it has received any trades.
            // GET /trades/wallets/{id}
            $wallet = $breet->getWallet($txn->provider_order_id);

            // The wallet response tells us the wallet is active/exists,
            // but does NOT tell us trade status — that comes via webhook.
            // This job is just a safety net; webhooks are the primary trigger.
            //
            // If wallet has trades (breet_transaction_id stored from webhook),
            // we check the specific transaction instead.
            if (!empty($txn->provider_reference)) {
                // provider_reference = Breet transaction ID (set by webhook handler)
                $data   = $breet->getTransaction($txn->provider_reference);
                $status = $data['status'] ?? 'pending';

                if ($status === 'completed') {
                    self::markCompleted($txn, $data);
                    return;
                }

                if ($status === 'failed') {
                    self::markFailed($txn, $data);
                    return;
                }
            }

            // No transaction yet — crypto hasn't arrived. Retry later.

            $this->release(30);

        } catch (\Exception $e) {
            Log::error('PollSellStatus failed', [
                'reference' => $txn->reference,
                'error'     => $e->getMessage(),
            ]);
            $this->release(30);
        }
    }

    public static function markCompleted(Transaction $txn, array $data): void
    {
        $amountSettled  = $data['amount']         ?? 0;
        $feeAmount      = $data['feeAmount']      ?? 0;
        $rate           = $data['rate']           ?? 0;
        $cryptoReceived = $data['cryptoReceived'] ?? $txn->crypto_amount;
        $txHash         = $data['txHash']         ?? null;

        $txn->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'amount'       => $amountSettled,
            'net_amount'   => $amountSettled,
            'provider_fee' => $feeAmount,
            'rate'         => $rate,
            'metadata'     => array_merge((array) ($txn->metadata ?? []), [
                'amount_settled'  => $amountSettled,
                'breet_fee'       => $feeAmount,
                'settlement_rate' => $rate,
                'crypto_received' => $cryptoReceived,
                'tx_hash'         => $txHash,
            ]),
        ]);

        AuditLog::record('transaction.sell_completed', [
            'user_id'        => $txn->user_id,
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'new_values'     => [
                'reference'      => $txn->reference,
                'amount_settled' => $amountSettled,
                'bank'           => $txn->account_number,
                'tx_hash'        => $txHash,
            ],
        ]);

        $txn->user->notify(new \App\Notifications\TransactionCompletedNotification($txn));

        Log::info('Sell completed', [
            'reference'      => $txn->reference,
            'amount_settled' => $amountSettled,
            'tx_hash'        => $txHash,
        ]);
    }

    public static function markFailed(Transaction $txn, array $data): void
    {
        $txn->update([
            'status'         => 'failed',
            'failed_at'      => now(),
            'failure_reason' => $data['reason'] ?? 'Transaction failed on Breet.',
        ]);

        AuditLog::record('transaction.sell_failed', [
            'user_id'        => $txn->user_id,
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
        ]);

        $txn->user->notify(new \App\Notifications\TransactionFailedNotification($txn));
    }
}