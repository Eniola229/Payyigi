<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\Breet\BreetService;
use App\Jobs\ProcessBreetWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BreetWebhookController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    /**
     * Entry point for all Breet webhooks.
     *
     * Breet sends webhooks for:
     *  - order.confirming     → crypto detected on chain
     *  - order.completed      → crypto confirmed, NGN sent to bank
     *  - order.failed         → order failed
     *  - order.expired        → user didn't send crypto in time
     *  - payout.completed     → bank transfer successful
     *  - payout.failed        → bank transfer failed
     */
    public function handle(Request $request): JsonResponse
    {
        // ── 1. Validate signature ────────────────────────────────────────────
        $signature = $request->header('X-Breet-Signature') ?? $request->header('x-breet-signature');
        $payload   = $request->getContent();

        if (!$signature || !$this->breet->validateWebhookSignature($payload, $signature)) {
            Log::warning('Breet webhook: invalid signature', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $data      = $request->json()->all();
        $eventType = $data['event'] ?? null;
        $orderData = $data['data']  ?? [];

        // ── 2. Log the webhook immediately (idempotency) ──────────────────────
        $log = WebhookLog::create([
            'source'         => 'breet',
            'event_type'     => $eventType,
            'breet_order_id' => $orderData['id'] ?? $orderData['order_id'] ?? null,
            'payload'        => $data,
            'status'         => 'pending',
        ]);

        // ── 3. Dispatch to queue for processing (respond immediately to Breet) ─
        ProcessBreetWebhook::dispatch($log->id);

        return response()->json(['message' => 'Webhook received.'], 200);
    }
}
