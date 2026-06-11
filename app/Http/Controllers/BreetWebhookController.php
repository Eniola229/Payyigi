<?php

namespace App\Http\Controllers;

use App\Jobs\PollSellStatus;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BreetWebhookController extends Controller
{
    /**
     * POST /webhooks/breet
     *
     * Breet sends a webhook when a deposit is detected on any of your wallets.
     * Verification: compare x-webhook-secret header against your stored secret.
     *
     * Payload event types:
     *   trade.address.created — new wallet address generated (no trade yet)
     *   trade.pending         — crypto detected on-chain, not yet confirmed
     *   trade.completed       — confirmed, settled to bank
     *   trade.flagged         — below minimum deposit, funds held
     */
    public function handle(Request $request, BreetService $breet): \Illuminate\Http\JsonResponse
    {
        // ── VERIFICATION ──────────────────────────────────────────────────────
        // Breet sends the webhook secret as a plain header — NOT an HMAC signature.
        $incomingSecret = $request->header('x-webhook-secret') ?? '';
        $expectedSecret = config('services.breet.webhook_secret', '');

        if (empty($expectedSecret) || !hash_equals($expectedSecret, $incomingSecret)) {
            Log::warning('Breet webhook: invalid secret', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid secret'], 401);
        }

        // ── PARSE PAYLOAD ─────────────────────────────────────────────────────
        $payload = $request->json()->all();

        $event     = $payload['event']   ?? null;
        $breetTxId = $payload['id']      ?? null;
        $status    = $payload['status']  ?? null;
        // vaultId is the numeric wallet ID Breet uses in trade webhooks.
        // This maps to provider_order_id in our transactions table.
        $walletId  = $payload['vaultId'] ?? null;

        Log::info('Breet webhook received', [
            'event'       => $event,
            'breet_tx_id' => $breetTxId,
            'wallet_id'   => $walletId,
            'status'      => $status,
        ]);

        // ── EARLY RETURN FOR NON-TRADE EVENTS ─────────────────────────────────
        // trade.address.created fires when a wallet address is generated — no trade yet.
        if ($event === 'trade.address.created') {
            Log::info('Breet webhook: wallet address created', [
                'address' => $payload['address'] ?? null,
                'asset'   => $payload['asset']   ?? null,
                'label'   => $payload['label']   ?? null,
            ]);
            return response()->json(['message' => 'Acknowledged'], 200);
        }

        // ── MATCH TRANSACTION ─────────────────────────────────────────────────
        if (!$walletId) {
            Log::warning('Breet webhook: no vaultId in payload', ['payload' => $payload]);
            return response()->json(['message' => 'No wallet ID in payload'], 200);
        }

        // For flagged events we must also match already-flagged transactions,
        // since Breet can re-send trade.flagged before we've processed the first one.
        // For completed events we broaden to also catch flagged transactions that
        // were resolved (customer topped up → Breet fires trade.completed on the
        // same vaultId).
        $txn = Transaction::where('provider_order_id', $walletId)
            ->where('type', 'sell')
            ->whereIn('status', ['awaiting_crypto', 'processing', 'flagged'])
            ->first();

        if (!$txn) {
            Log::info('Breet webhook: no matching pending transaction', ['wallet_id' => $walletId]);
            return response()->json(['message' => 'No matching transaction'], 200);
        }

        // Idempotency — skip if already completed with the same Breet tx ID
        if ($breetTxId && $txn->provider_reference === $breetTxId && $txn->isCompleted()) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        // Store Breet transaction ID for polling fallback
        if ($breetTxId && !$txn->provider_reference) {
            $txn->update(['provider_reference' => $breetTxId]);
        }

        // ── HANDLE EVENT ──────────────────────────────────────────────────────
        match ($event) {

            'trade.completed' => PollSellStatus::markCompleted($txn->fresh(), $payload),

            'trade.flagged' => $this->handleFlagged($txn, $payload),

            'trade.pending' => (function () use ($txn) {
                $txn->update(['status' => 'processing']);
                PollSellStatus::dispatch($txn)->delay(now()->addSeconds(15));
            })(),

            default => Log::info('Breet webhook: unhandled event', ['event' => $event]),
        };

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    // ── PRIVATE HANDLERS ──────────────────────────────────────────────────────

    private function handleFlagged(Transaction $txn, array $payload): void
    {
        // Guard: don't re-flag or re-notify if already flagged with same Breet tx ID.
        // Breet can resend trade.flagged — treat it as a no-op if we've seen it.
        $breetTxId = $payload['id'] ?? null;

        if ($txn->status === 'flagged' && $txn->provider_reference === $breetTxId) {
            Log::info('Breet webhook: trade.flagged already processed, skipping', [
                'reference'   => $txn->reference,
                'breet_tx_id' => $breetTxId,
            ]);
            return;
        }

        $txn->update([
            'status'             => 'flagged',
            'flagged_at'         => now(),
            'provider_reference' => $breetTxId ?? $txn->provider_reference,
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

        // Keep polling — a flagged transaction can self-resolve to completed if
        // the customer tops up and the combined total crosses the minimum.
        // Breet will fire trade.completed on the same vaultId when that happens,
        // but polling is a safety net in case the webhook is missed.
        PollSellStatus::dispatch($txn)->delay(now()->addMinutes(15));

        Log::info('Breet webhook: transaction flagged', [
            'reference'   => $txn->reference,
            'breet_tx_id' => $breetTxId,
            'amount_usd'  => $payload['amountInUSD'] ?? null,
        ]);
    }
}