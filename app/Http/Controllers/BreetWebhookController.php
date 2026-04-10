<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\Breet\BreetService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BreetWebhookController extends Controller
{
    public function __construct(
        private readonly BreetService  $breet,
        private readonly WalletService $walletService,
    ) {}

    /**
     * POST /api/v1/webhooks/breet
     * No auth middleware — verified via HMAC signature
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature  = $request->header('X-Breet-Signature')
                   ?? $request->header('X-Webhook-Signature')
                   ?? '';

        // Always log the raw webhook
        $webhookLog = WebhookLog::create([
            'source'         => 'breet',
            'event_type'     => $request->input('event', 'unknown'),
            'breet_order_id' => $request->input('data.id') ?? $request->input('data.order_id'),
            'payload'        => $request->all(),
            'status'         => 'pending',
        ]);

        // Verify HMAC signature
        if (!$this->breet->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Breet webhook: invalid signature', ['webhook_log_id' => $webhookLog->id]);
            $webhookLog->markFailed('Invalid signature');
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = $request->input('event');
        $data  = $request->input('data', []);

        try {
            match ($event) {
                'transaction.completed'  => $this->onCompleted($data),
                'transaction.failed'     => $this->onFailed($data),
                'transaction.processing' => $this->onProcessing($data),
                default => Log::info("Breet webhook: unhandled event [{$event}]"),
            };

            $webhookLog->markProcessed();
            return response()->json(['message' => 'OK']);

        } catch (\Exception $e) {
            $webhookLog->markFailed($e->getMessage());
            Log::error('Breet webhook error', ['error' => $e->getMessage(), 'event' => $event]);
            // Return 200 so Breet doesn't keep retrying for our internal errors
            return response()->json(['message' => 'Received.']);
        }
    }

    private function findTransaction(array $data): ?Transaction
    {
        return Transaction::where('breet_order_id', $data['id'] ?? '')
            ->orWhere('reference', $data['reference'] ?? '')
            ->first();
    }

    private function onCompleted(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction || $transaction->isCompleted()) return;

        DB::transaction(function () use ($transaction, $data) {
            // Credit wallet
            $balanceBefore = (float) $transaction->wallet->balance;
            $transaction->wallet->credit((float) $transaction->amount);

            $transaction->update([
                'status'                  => 'completed',
                'completed_at'            => now(),
                'balance_before'          => $balanceBefore,
                'balance_after'           => $transaction->wallet->fresh()->balance,
                'bank_transfer_reference' => $data['payout_reference'] ?? null,
                'breet_response'          => array_merge(
                    $transaction->breet_response ?? [],
                    ['completion_data' => $data]
                ),
            ]);

            AuditLog::record('transaction.sell_completed', [
                'user_id'        => $transaction->user_id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => ['amount' => $transaction->amount, 'reference' => $transaction->reference],
            ]);

            $transaction->user->notify(
                new \App\Notifications\TransactionCompletedNotification($transaction)
            );
        });
    }

    private function onFailed(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction || $transaction->isFailed()) return;

        $transaction->update([
            'status'         => 'failed',
            'failed_at'      => now(),
            'failure_reason' => $data['reason'] ?? 'Failed on payment processor.',
        ]);

        $transaction->user->notify(
            new \App\Notifications\TransactionFailedNotification($transaction)
        );
    }

    private function onProcessing(array $data): void
    {
        $transaction = $this->findTransaction($data);
        if (!$transaction) return;

        $transaction->update([
            'status'         => 'converting',
            'crypto_tx_hash' => $data['tx_hash'] ?? null,
        ]);
    }
}
