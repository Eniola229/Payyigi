<?php

namespace App\Http\Controllers;

use App\Jobs\PollSellStatus;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Breet Webhook Handler
 * POST /webhooks/breet
 */
class BreetWebhookController extends Controller
{
    public function handle(Request $request, BreetService $breet): JsonResponse
    {
        // ── 1. VERIFY WEBHOOK SECRET ──────────────────────────────────────────
        $incomingSecret = $request->header('x-webhook-secret', '');

        if (!$breet->verifyWebhookSecret($incomingSecret)) {
            Log::warning('Breet webhook: invalid secret', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid secret'], 401);
        }

        // ── 2. PARSE PAYLOAD ──────────────────────────────────────────────────
        $payload = $request->json()->all();

        $event     = $payload['event']   ?? null;
        $breetTxId = $payload['id']      ?? null;
        $status    = $payload['status']  ?? null;
        $vaultId   = isset($payload['vaultId']) ? (string) $payload['vaultId'] : null;

        Log::info('Breet webhook received', [
            'event'        => $event,
            'breet_tx_id'  => $breetTxId,
            'vault_id'     => $vaultId,
            'status'       => $status,
        ]);

        // ── 3. HANDLE NON-TRADE EVENTS ────────────────────────────────────────
        if ($event === 'trade.address.created') {
            Log::info('Breet webhook: wallet address created (no action needed)', [
                'address' => $payload['address'] ?? null,
                'asset'   => $payload['asset']   ?? null,
                'label'   => $payload['label']   ?? null,
            ]);
            return response()->json(['message' => 'Acknowledged'], 200);
        }

        if (!in_array($event, ['trade.pending', 'trade.completed', 'trade.flagged'])) {
            Log::info('Breet webhook: ignoring non-trade event', ['event' => $event]);
            return response()->json(['message' => 'Ignored'], 200);
        }

        // ── 4. REQUIRE vaultId ────────────────────────────────────────────────
        if (empty($vaultId)) {
            Log::error('Breet webhook: missing vaultId in payload', ['payload' => $payload]);
            return response()->json(['message' => 'No vaultId in payload'], 200);
        }

        // ── 5. MATCH TRANSACTION ──────────────────────────────────────────────
        $txn = Transaction::where('breet_vault_id', $vaultId)
            ->where('type', 'sell')
            ->whereIn('status', ['awaiting_crypto', 'processing', 'flagged'])
            ->latest()
            ->first();

        if (!$txn) {
            Log::warning('Breet webhook: no matching transaction', [
                'vault_id'    => $vaultId,
                'breet_tx_id' => $breetTxId,
                'event'       => $event,
            ]);
            return response()->json(['message' => 'No matching transaction'], 200);
        }

        // ── 6. IDEMPOTENCY CHECK ──────────────────────────────────────────────
        if (
            $breetTxId
            && $txn->provider_reference === $breetTxId
            && $txn->isCompleted()
        ) {
            Log::info('Breet webhook: already processed, skipping', [
                'reference'   => $txn->reference,
                'breet_tx_id' => $breetTxId,
            ]);
            return response()->json(['message' => 'Already processed'], 200);
        }

        Log::info('Breet webhook: matched transaction', [
            'reference'    => $txn->reference,
            'vault_id'     => $vaultId,
            'breet_tx_id'  => $breetTxId,
            'event'        => $event,
            'txn_status'   => $txn->status,
        ]);

        // ── 7. STORE BREET TRANSACTION ID ────────────────────────────────────
        if ($breetTxId && empty($txn->provider_reference)) {
            $txn->update(['provider_reference' => $breetTxId]);
            $txn = $txn->fresh();
        }

        // ── 8. DISPATCH TO EVENT HANDLERS ────────────────────────────────────
        match ($event) {
            'trade.pending'   => $this->handlePending($txn, $payload),
            'trade.completed' => $this->handleCompleted($txn, $payload, $breet),
            'trade.flagged'   => $this->handleFlagged($txn, $payload),
            default           => null,
        };

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EVENT HANDLERS
    // ─────────────────────────────────────────────────────────────────────────

    private function handlePending(Transaction $txn, array $payload): void
    {
        if ($txn->status === 'awaiting_crypto') {
            $txn->update(['status' => 'processing']);
        }

        PollSellStatus::dispatch($txn->fresh())->delay(now()->addSeconds(30));

        Log::info('Breet webhook: trade pending', [
            'reference'     => $txn->reference,
            'confirmations' => $payload['confirmations'] ?? null,
        ]);
    }

    /**
     * trade.completed - Updated to trigger payout
     */
    private function handleCompleted(Transaction $txn, array $payload, BreetService $breet): void
    {
        if ($txn->isCompleted()) {
            Log::info('Breet webhook: trade.completed already processed', [
                'reference' => $txn->reference,
            ]);
            return;
        }

        $amountSettled = $payload['amountSettled'] ?? $payload['amount'] ?? 0;

        if ($amountSettled <= 0) {
            Log::error('Breet webhook: invalid settlement amount', [
                'reference' => $txn->reference,
                'amount' => $amountSettled
            ]);
            return;
        }

        try {
            DB::transaction(function () use ($txn, $payload, $amountSettled, $breet) {
                // Update to processing state
                $txn->update([
                    'status' => 'processing',
                    'metadata' => array_merge((array) ($txn->metadata ?? []), [
                        'settlement_amount' => $amountSettled,
                        'settlement_rate' => $payload['settlementRate'] ?? $payload['rate'] ?? null,
                    ]),
                ]);

                // ── CALL BREET PAYOUT API ────────────────────────────────────
                $payoutResult = $breet->createWithdrawal([
                    'bankCode' => $txn->bank_code,
                    'accountNumber' => $txn->account_number,
                    'accountName' => $txn->account_name,
                    'amount' => $amountSettled,
                    'currency' => 'NGN',
                    'narration' => "PayYigi sell order #{$txn->reference}",
                    'reference' => $txn->reference,
                ]);

                // Store payout reference
                $txn->update([
                    'provider_payout_id' => $payoutResult['id'] ?? null,
                    'provider_payout_status' => $payoutResult['status'] ?? 'pending',
                ]);

                // ── MARK AS COMPLETED ────────────────────────────────────────
                PollSellStatus::markCompleted($txn->fresh(), [
                    'amountSettled'  => $amountSettled,
                    'feeAmountInUsd' => $payload['feeAmountInUsd'] ?? $payload['feeAmount'] ?? 0,
                    'rate'           => $payload['rate'] ?? 0,
                    'settlementRate' => $payload['settlementRate'] ?? $payload['rate'] ?? 0,
                    'cryptoReceived' => $payload['cryptoAmount'] ?? null,
                    'txHash'         => $payload['txHash'] ?? null,
                    'markupPercent'  => $payload['markupPercent'] ?? null,
                    'markupAmount'   => $payload['markupAmount'] ?? null,
                    'flagFeeUSD'     => $payload['flagFeeUSD'] ?? 0,
                    'walletCredited' => $payload['walletCredited'] ?? null,
                ]);

                // ── MONITOR PAYOUT STATUS ────────────────────────────────────
                \App\Jobs\MonitorPayoutStatus::dispatch($txn->fresh())->delay(now()->addSeconds(30));
            });

        } catch (\Exception $e) {
            Log::error('Breet payout failed', [
                'reference' => $txn->reference,
                'error' => $e->getMessage(),
            ]);

            $txn->update([
                'status' => 'failed',
                'failure_reason' => 'Payout failed: ' . $e->getMessage(),
                'failed_at' => now(),
            ]);
        }
    }

    private function handleFlagged(Transaction $txn, array $payload): void
    {
        $breetTxId = $payload['id'] ?? null;

        if ($txn->status === 'flagged' && $txn->provider_reference === $breetTxId) {
            Log::info('Breet webhook: trade.flagged already recorded, skipping', [
                'reference'   => $txn->reference,
                'breet_tx_id' => $breetTxId,
            ]);
            return;
        }

        $txn->update([
            'status'             => 'flagged',
            'flagged_at'         => now(),
            'provider_reference' => $breetTxId ?? $txn->provider_reference,
            'failure_reason'     => 'Deposit below minimum — funds held by Breet pending resolution.',
            'metadata'           => array_merge((array) ($txn->metadata ?? []), [
                'breet_tx_id'   => $breetTxId,
                'crypto_amount' => $payload['cryptoAmount'] ?? null,
                'amount_usd'    => $payload['amountInUSD']  ?? null,
                'flag_fee_usd'  => $payload['flagFeeUSD']   ?? 0,
                'tx_hash'       => $payload['txHash']       ?? null,
            ]),
        ]);

        AuditLog::record('transaction.sell_flagged', [
            'user_id'        => $txn->user_id,
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'new_values'     => [
                'reference'   => $txn->reference,
                'breet_tx_id' => $breetTxId,
                'amount_usd'  => $payload['amountInUSD'] ?? null,
                'tx_hash'     => $payload['txHash']      ?? null,
            ],
        ]);

        $txn->user->notify(new \App\Notifications\TransactionFlaggedNotification($txn->fresh()));

        PollSellStatus::dispatch($txn->fresh())->delay(now()->addMinutes(15));

        Log::info('Breet webhook: transaction flagged', [
            'reference'   => $txn->reference,
            'breet_tx_id' => $breetTxId,
            'amount_usd'  => $payload['amountInUSD'] ?? null,
            'flag_fee'    => $payload['flagFeeUSD']  ?? 0,
        ]);
    }
}