<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Korapay\KorapayPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KorapayWebhookController extends Controller
{
    public function __construct(private readonly KorapayPayoutService $korapay) {}

    /**
     * POST /api/v1/webhooks/korapay
     *
     * Security:
     * - Signature verified via HMAC SHA256 on the data object using secret key
     * - Always returns HTTP 200 (even on error) so Korapay stops retrying
     * - Idempotency check prevents double-processing
     * - All activity logged to audit_logs
     */
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->all();
        $event     = $payload['event']     ?? 'unknown';
        $data      = $payload['data']      ?? [];
        $reference = $data['reference']    ?? null;
        $signature = $request->header('x-korapay-signature') ?? '';

        // ── Step 1: Log raw webhook immediately (before anything else) ────────
        AuditLog::create([
            'user_id'    => null,
            'event'      => "webhook.korapay.{$event}.received",
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => [
                'event'     => $event,
                'reference' => $reference,
                'amount'    => $data['amount']   ?? null,
                'status'    => $data['status']   ?? null,
                'currency'  => $data['currency'] ?? null,
            ],
        ]);

        // ── Step 2: Verify HMAC signature ─────────────────────────────────────
        // Korapay signs ONLY the data object — not the full payload
        if (!$this->korapay->verifyWebhookSignature($data, $signature)) {
            Log::warning('Korapay webhook: invalid signature', [
                'event'     => $event,
                'reference' => $reference,
                'ip'        => $request->ip(),
            ]);

            AuditLog::create([
                'user_id'    => null,
                'event'      => 'webhook.korapay.signature_failed',
                'ip_address' => $request->ip(),
                'new_values' => ['event' => $event, 'reference' => $reference],
            ]);

            // Still return 200 — returning 4xx causes Korapay to retry
            return response()->json(['message' => 'OK'], 200);
        }

        // ── Step 3: Process event ─────────────────────────────────────────────
        try {
            match ($event) {
                'transfer.success' => $this->onTransferSuccess($data, $request),
                'transfer.failed'  => $this->onTransferFailed($data, $request),
                default => AuditLog::create([
                    'user_id'    => null,
                    'event'      => "webhook.korapay.{$event}.unhandled",
                    'ip_address' => $request->ip(),
                    'new_values' => ['event' => $event, 'reference' => $reference],
                ]),
            };

        } catch (\Exception $e) {
            Log::error('Korapay webhook processing error', [
                'event'     => $event,
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            AuditLog::create([
                'user_id'    => null,
                'event'      => "webhook.korapay.{$event}.error",
                'ip_address' => $request->ip(),
                'new_values' => [
                    'reference' => $reference,
                    'error'     => $e->getMessage(),
                ],
            ]);
        }

        // ── Always return 200 ─────────────────────────────────────────────────
        return response()->json(['message' => 'OK'], 200);
    }

    private function onTransferSuccess(array $data, Request $request): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) return;

        // Find transaction by our reference or Korapay's reference
        $txn = Transaction::where('reference', $reference)
                          ->orWhere('provider_reference', $reference)
                          ->where('type', 'withdraw')
                          ->first();

        if (!$txn) {
            AuditLog::create([
                'user_id'    => null,
                'event'      => 'webhook.korapay.transfer_success.not_found',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference],
            ]);
            return;
        }

        // ── Idempotency check ─────────────────────────────────────────────────
        if ($txn->isCompleted()) {
            AuditLog::create([
                'user_id'    => $txn->user_id,
                'event'      => 'webhook.korapay.transfer_success.duplicate',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference, 'txn_id' => $txn->id],
            ]);
            return;
        }

        $txn->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        // Notify user
        $txn->user->notify(new \App\Notifications\WithdrawalCompletedNotification($txn));

        // Full audit log with all Korapay data
        AuditLog::create([
            'user_id'        => $txn->user_id,
            'event'          => 'webhook.korapay.transfer_success.processed',
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'ip_address'     => $request->ip(),
            'new_values'     => [
                'reference'       => $reference,
                'amount'          => $data['amount']    ?? null,
                'fee'             => $data['fee']       ?? null,
                'currency'        => $data['currency']  ?? null,
                'status'          => $data['status']    ?? null,
                'narration'       => $data['narration'] ?? null,
                'korapay_message' => $data['message']   ?? null,
                'txn_id'          => $txn->id,
                'user_id'         => $txn->user_id,
            ],
        ]);
    }

    private function onTransferFailed(array $data, Request $request): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) return;

        $txn = Transaction::where('reference', $reference)
                          ->orWhere('provider_reference', $reference)
                          ->where('type', 'withdraw')
                          ->first();

        if (!$txn) {
            AuditLog::create([
                'user_id'    => null,
                'event'      => 'webhook.korapay.transfer_failed.not_found',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference],
            ]);
            return;
        }

        // ── Idempotency check ─────────────────────────────────────────────────
        if ($txn->isFailed()) {
            AuditLog::create([
                'user_id'    => $txn->user_id,
                'event'      => 'webhook.korapay.transfer_failed.duplicate',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference],
            ]);
            return;
        }

        $txn->update([
            'status'         => 'failed',
            'failed_at'      => now(),
            'failure_reason' => $data['message'] ?? 'Bank transfer failed.',
        ]);

        // Refund wallet
        $txn->wallet->credit((float) $txn->amount);

        $txn->user->notify(new \App\Notifications\WithdrawalFailedNotification($txn));

        // Full audit log with all Korapay data
        AuditLog::create([
            'user_id'        => $txn->user_id,
            'event'          => 'webhook.korapay.transfer_failed.processed',
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'ip_address'     => $request->ip(),
            'new_values'     => [
                'reference'       => $reference,
                'amount'          => $data['amount']    ?? null,
                'fee'             => $data['fee']       ?? null,
                'currency'        => $data['currency']  ?? null,
                'status'          => $data['status']    ?? null,
                'korapay_message' => $data['message']   ?? null,
                'refunded'        => true,
                'txn_id'          => $txn->id,
                'user_id'         => $txn->user_id,
            ],
        ]);
    }
}