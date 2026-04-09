<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Notifications\TransactionCompletedNotification;
use App\Notifications\TransactionFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessBreetWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(private readonly string $webhookLogId) {}

    public function handle(): void
    {
        $log = WebhookLog::find($this->webhookLogId);

        if (!$log || $log->status === 'processed') {
            return; // Already handled — idempotency
        }

        $payload   = $log->payload;
        $eventType = $log->event_type;
        $orderData = $payload['data'] ?? [];

        try {
            match ($eventType) {
                'order.confirming' => $this->handleOrderConfirming($orderData),
                'order.completed'  => $this->handleOrderCompleted($orderData),
                'order.failed'     => $this->handleOrderFailed($orderData),
                'order.expired'    => $this->handleOrderExpired($orderData),
                'payout.completed' => $this->handlePayoutCompleted($orderData),
                'payout.failed'    => $this->handlePayoutFailed($orderData),
                default            => Log::info("Breet webhook: unhandled event [{$eventType}]"),
            };

            $log->markProcessed();

        } catch (\Throwable $e) {
            Log::error('ProcessBreetWebhook failed', [
                'webhook_log_id' => $this->webhookLogId,
                'event'          => $eventType,
                'error'          => $e->getMessage(),
            ]);
            $log->markFailed($e->getMessage());
            throw $e; // Re-throw so queue retries
        }
    }

    // ─── Event Handlers ───────────────────────────────────────────────────────

    /**
     * Crypto detected on-chain — update status to confirming.
     */
    private function handleOrderConfirming(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction) return;

        $txHash = $data['tx_hash'] ?? $data['transaction_hash'] ?? null;

        $transaction->update([
            'status'         => 'confirming',
            'crypto_tx_hash' => $txHash,
        ]);

        Log::info("Order confirming: {$transaction->reference}");
    }

    /**
     * Order fully completed — crypto confirmed, NGN sent to bank by Breet.
     * Credit the user's wallet with the net NGN amount.
     */
    private function handleOrderCompleted(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction || $transaction->isCompleted()) return;

        DB::transaction(function () use ($transaction, $data) {
            $wallet = $transaction->wallet;

            $balanceBefore = (float) $wallet->balance;
            $netAmount     = (float) $transaction->net_amount;

            // Credit the wallet
            $wallet->credit($netAmount);

            $balanceAfter = (float) $wallet->fresh()->balance;

            // Update transaction
            $transaction->update([
                'status'                 => 'completed',
                'balance_before'         => $balanceBefore,
                'balance_after'          => $balanceAfter,
                'crypto_tx_hash'         => $data['tx_hash'] ?? $transaction->crypto_tx_hash,
                'bank_transfer_reference'=> $data['payout_reference'] ?? null,
                'completed_at'           => now(),
            ]);

            AuditLog::record('transaction.sell_completed', [
                'user_id'        => $transaction->user_id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'reference'  => $transaction->reference,
                    'net_amount' => $netAmount,
                ],
            ]);

            // Notify user
            $transaction->user->notify(new TransactionCompletedNotification($transaction));
        });

        Log::info("Order completed: {$transaction->reference}");
    }

    /**
     * Order failed on Breet's side.
     */
    private function handleOrderFailed(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction) return;

        $transaction->update([
            'status'         => 'failed',
            'failure_reason' => $data['reason'] ?? 'Order failed on processing.',
            'failed_at'      => now(),
        ]);

        $transaction->user->notify(new TransactionFailedNotification($transaction));

        Log::warning("Order failed: {$transaction->reference}");
    }

    /**
     * Order expired — user didn't send crypto in time.
     */
    private function handleOrderExpired(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction) return;

        $transaction->update([
            'status'    => 'expired',
            'failed_at' => now(),
        ]);

        Log::info("Order expired: {$transaction->reference}");
    }

    /**
     * Payout (NGN withdrawal) completed — update withdrawal transaction.
     */
    private function handlePayoutCompleted(array $data): void
    {
        $reference   = $data['reference'] ?? null;
        $transaction = Transaction::where('reference', $reference)
                                  ->where('type', 'withdraw')
                                  ->first();

        if (!$transaction || $transaction->isCompleted()) return;

        $transaction->update([
            'status'                  => 'completed',
            'bank_transfer_reference' => $data['payout_reference'] ?? null,
            'completed_at'            => now(),
        ]);

        $transaction->user->notify(new TransactionCompletedNotification($transaction));

        Log::info("Withdrawal payout completed: {$transaction->reference}");
    }

    /**
     * Payout (NGN withdrawal) failed — reverse the debit.
     */
    private function handlePayoutFailed(array $data): void
    {
        $reference   = $data['reference'] ?? null;
        $transaction = Transaction::where('reference', $reference)
                                  ->where('type', 'withdraw')
                                  ->first();

        if (!$transaction) return;

        DB::transaction(function () use ($transaction, $data) {
            $wallet = $transaction->wallet;

            // Reverse the debit — refund back to wallet
            $wallet->credit((float) $transaction->amount);

            $transaction->update([
                'status'         => 'failed',
                'failure_reason' => $data['reason'] ?? 'Bank transfer failed.',
                'failed_at'      => now(),
                'balance_after'  => $wallet->fresh()->balance,
            ]);

            // Create a refund credit transaction for the audit trail
            Transaction::create([
                'user_id'        => $transaction->user_id,
                'wallet_id'      => $transaction->wallet_id,
                'type'           => 'deposit',
                'entry_type'     => 'credit',
                'currency'       => 'NGN',
                'amount'         => $transaction->amount,
                'fee'            => 0,
                'breet_fee'      => 0,
                'net_amount'     => $transaction->amount,
                'balance_before' => $wallet->balance - $transaction->amount,
                'balance_after'  => $wallet->fresh()->balance,
                'status'         => 'completed',
                'notes'          => "Refund for failed withdrawal: {$transaction->reference}",
                'completed_at'   => now(),
                'session_id'     => 'system',
                'ip_address'     => '0.0.0.0',
            ]);
        });

        $transaction->user->notify(new TransactionFailedNotification($transaction));

        Log::warning("Withdrawal payout failed + reversed: {$transaction->reference}");
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function findTransaction(array $data): ?Transaction
    {
        $breetOrderId = $data['id'] ?? $data['order_id'] ?? null;
        $reference    = $data['reference'] ?? null;

        if ($breetOrderId) {
            $transaction = Transaction::where('breet_order_id', $breetOrderId)->first();
            if ($transaction) return $transaction;
        }

        if ($reference) {
            return Transaction::where('reference', $reference)
                              ->orWhere('breet_reference', $reference)
                              ->first();
        }

        Log::warning('ProcessBreetWebhook: could not find transaction', ['data' => $data]);
        return null;
    }
}
