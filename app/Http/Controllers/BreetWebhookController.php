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
     * The payload contains the wallet ID and a Breet transaction ID.
     * We match the wallet ID to our transaction, store the Breet transaction ID,
     * then immediately check the transaction status to complete/fail it.
     */
    public function handle(Request $request, BreetService $breet): \Illuminate\Http\JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature  = $request->header('x-breet-signature') ?? '';

        // Verify the webhook came from Breet
        if (!$breet->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Breet webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload   = $request->json()->all();
        $event     = $payload['event']  ?? null;
        $data      = $payload['data']   ?? [];
        $walletId  = $data['wallet']    ?? $data['walletId'] ?? null; // Breet wallet ID
        $breetTxId = $data['id']        ?? null;                      // Breet transaction ID
        $status    = $data['status']    ?? null;

        Log::info('Breet webhook received', [
            'event'       => $event,
            'wallet_id'   => $walletId,
            'breet_tx_id' => $breetTxId,
            'status'      => $status,
        ]);

        // Find our transaction by the wallet ID (stored as provider_order_id)
        if (!$walletId) {
            return response()->json(['message' => 'No wallet ID in payload'], 200);
        }

        $txn = Transaction::where('provider_order_id', $walletId)
            ->where('type', 'sell')
            ->whereIn('status', ['awaiting_crypto', 'processing'])
            ->first();

        if (!$txn) {
            Log::info('Breet webhook: no matching pending transaction for wallet', ['wallet_id' => $walletId]);
            return response()->json(['message' => 'No matching transaction'], 200);
        }

        // Store the Breet transaction ID so PollSellStatus can use it
        if ($breetTxId && !$txn->provider_reference) {
            $txn->update(['provider_reference' => $breetTxId]);
        }

        // Handle the event
        if ($status === 'completed') {
            PollSellStatus::markCompleted($txn->fresh(), $data);
        } elseif ($status === 'failed') {
            PollSellStatus::markFailed($txn->fresh(), $data);
        } else {
            // processing/pending — update status and let poll job handle completion
            $txn->update(['status' => 'processing']);
            PollSellStatus::dispatch($txn)->delay(now()->addSeconds(15));
        }

        return response()->json(['message' => 'Webhook processed'], 200);
    }
}