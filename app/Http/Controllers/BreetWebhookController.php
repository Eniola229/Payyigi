<?php

namespace App\Http\Controllers;

use App\Jobs\PollSellStatus;
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
     *   trade.completed       — confirmed, account credited
     *   trade.flagged         — below minimum deposit, funds held
     */
    public function handle(Request $request, BreetService $breet): \Illuminate\Http\JsonResponse
    {
        // ── VERIFICATION ──────────────────────────────────────────────────────
        $incomingSecret = $request->header('x-webhook-secret') ?? '';
        $expectedSecret = config('services.breet.webhook_secret', '');

        if (empty($expectedSecret) || !hash_equals($expectedSecret, $incomingSecret)) {
            Log::warning('Breet webhook: invalid secret', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid secret'], 401);
        }

        // ── PARSE PAYLOAD ─────────────────────────────────────────────────────
        $payload = $request->json()->all();

        $event     = $payload['event']   ?? null;
        $breetTxId = $payload['id']      ?? null;
        $status    = $payload['status']  ?? null;
        $walletId  = $payload['vaultId'] ?? null; // vaultId maps to our provider_order_id

        Log::info('Breet webhook received', [
            'event'       => $event,
            'breet_tx_id' => $breetTxId,
            'wallet_id'   => $walletId,
            'status'      => $status,
        ]);

        // ── EARLY RETURN FOR NON-TRADE EVENTS ─────────────────────────────────
        // trade.address.created fires when a wallet address is generated — no trade yet.
        if ($event === 'trade.address.created') {
           
            return response()->json(['message' => 'Acknowledged'], 200);
        }

        // ── MATCH TRANSACTION ─────────────────────────────────────────────────
        if (!$walletId) {
            Log::warning('Breet webhook: no vaultId in payload', ['payload' => $payload]);
            return response()->json(['message' => 'No wallet ID in payload'], 200);
        }

        $txn = Transaction::where('provider_order_id', $walletId)
            ->where('type', 'sell')
            ->whereIn('status', ['awaiting_crypto', 'processing'])
            ->first();

        if (!$txn) {
            Log::info('Breet webhook: no matching pending transaction', ['wallet_id' => $walletId]);
            return response()->json(['message' => 'No matching transaction'], 200);
        }

        // Idempotency — skip if already processed with same Breet tx ID
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
            'trade.flagged'   => $txn->update(['status' => 'flagged']),
            'trade.pending'   => (function () use ($txn) {
                $txn->update(['status' => 'processing']);
                PollSellStatus::dispatch($txn)->delay(now()->addSeconds(15));
            })(),
            default => Log::info('Breet webhook: unhandled event', ['event' => $event]),
        };

        return response()->json(['message' => 'Webhook processed'], 200);
    }
}