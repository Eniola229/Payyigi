<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Korapay\KorapayVirtualAccountService;
use App\Services\Korapay\KorapayPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KorapayWebhookController extends Controller
{
    public function __construct(
        private readonly KorapayVirtualAccountService $virtualAccountService,
        private readonly KorapayPayoutService         $payoutService,
    ) {}

    /**
     * POST /api/v1/webhooks/korapay
     * Handles both:
     *   charge.success  → virtual account top-up
     *   transfer.success → withdrawal completed
     *   transfer.failed  → withdrawal failed, refund wallet
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $payload    = $request->all();
        $event      = $payload['event']  ?? 'unknown';
        $data       = $payload['data']   ?? [];
        $reference  = $data['reference'] ?? null;
        $signature  = $request->header('x-korapay-signature') ?? '';

        // ── Step 1: Log raw webhook immediately ───────────────────────────────
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

        // ── Step 2: Verify signature ──────────────────────────────────────────
        if (!$this->virtualAccountService->verifyWebhookSignature($rawPayload, $signature)) {
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

            return response()->json(['message' => 'OK'], 200);
        }

        // ── Step 3: Route event ───────────────────────────────────────────────
        try {
            match ($event) {
                'charge.success'   => $this->onChargeSuccess($data, $request),
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
            Log::error('Korapay webhook error', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            AuditLog::create([
                'user_id'    => null,
                'event'      => "webhook.korapay.{$event}.error",
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference, 'error' => $e->getMessage()],
            ]);
        }

        return response()->json(['message' => 'OK'], 200);
    }

    /**
     * Virtual account top-up received.
     * Deduct fee → credit user wallet → create transaction record.
     */
    private function onChargeSuccess(array $data, Request $request): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) return;

        // Idempotency — check if already processed
        $exists = Transaction::where('provider_reference', $reference)
                             ->where('type', 'deposit')
                             ->exists();

        if ($exists) {
            AuditLog::create([
                'user_id'    => null,
                'event'      => 'webhook.korapay.charge_success.duplicate',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference],
            ]);
            return;
        }

        // Find user by their virtual account number
        $virtualAccountNumber = $data['virtual_bank_account_details']['virtual_bank_account']['account_number'] ?? null;

        if (!$virtualAccountNumber) {
            AuditLog::create([
                'user_id'    => null,
                'event'      => 'webhook.korapay.charge_success.no_virtual_account',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference, 'data' => $data],
            ]);
            return;
        }

        $user = User::where('virtual_account_number', $virtualAccountNumber)->first();

        if (!$user) {
            AuditLog::create([
                'user_id'    => null,
                'event'      => 'webhook.korapay.charge_success.user_not_found',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference, 'account_number' => $virtualAccountNumber],
            ]);
            return;
        }

        // Verify payment with Korapay before crediting
        $verified = $this->virtualAccountService->verifyCharge($reference);

        if (($verified['status'] ?? '') !== 'success') {
            AuditLog::create([
                'user_id'    => $user->id,
                'event'      => 'webhook.korapay.charge_success.verification_failed',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference, 'verified_status' => $verified['status'] ?? null],
            ]);
            return;
        }

        $grossAmount   = (float) ($data['amount'] ?? 0) / 100; // Korapay sends in kobo
        $korapayFee    = (float) ($data['fee']    ?? 0) / 100;
        $feePct        = config('payyigi.topup_fee_percent', 0.5);
        $platformFee   = round($grossAmount * ($feePct / 100), 2);
        $totalFee      = round($korapayFee + $platformFee, 2);
        $netAmount     = round($grossAmount - $totalFee, 2);

        if ($netAmount <= 0) {
            AuditLog::create([
                'user_id'    => $user->id,
                'event'      => 'webhook.korapay.charge_success.amount_too_small',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference, 'gross' => $grossAmount, 'fee' => $totalFee],
            ]);
            return;
        }

        DB::transaction(function () use ($user, $reference, $grossAmount, $korapayFee, $platformFee, $totalFee, $netAmount, $data, $request) {
            $wallet        = $user->wallet;
            $balanceBefore = (float) $wallet->balance;

            $wallet->credit($netAmount);

            $txn = Transaction::create([
                'user_id'            => $user->id,
                'wallet_id'          => $wallet->id,
                'type'               => 'deposit',
                'entry_type'         => 'credit',
                'currency'           => 'NGN',
                'amount'             => $grossAmount,
                'fee'                => $platformFee,
                'provider_fee'       => $korapayFee,
                'net_amount'         => $netAmount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $wallet->fresh()->balance,
                'provider_reference' => $reference,
                'status'             => 'completed',
                'completed_at'       => now(),
                'ip_address'         => $request->ip(),
                'metadata'           => [
                    'payer_account_name'   => $data['virtual_bank_account_details']['payer_bank_account']['account_name']   ?? null,
                    'payer_account_number' => $data['virtual_bank_account_details']['payer_bank_account']['account_number'] ?? null,
                    'payer_bank_name'      => $data['virtual_bank_account_details']['payer_bank_account']['bank_name']      ?? null,
                    'virtual_account'      => $data['virtual_bank_account_details']['virtual_bank_account']['account_number'] ?? null,
                ],
            ]);

            $user->notify(new \App\Notifications\WalletTopUpNotification($txn));

            AuditLog::create([
                'user_id'        => $user->id,
                'event'          => 'webhook.korapay.charge_success.processed',
                'auditable_type' => Transaction::class,
                'auditable_id'   => $txn->id,
                'ip_address'     => $request->ip(),
                'new_values'     => [
                    'reference'    => $reference,
                    'gross_amount' => $grossAmount,
                    'korapay_fee'  => $korapayFee,
                    'platform_fee' => $platformFee,
                    'net_credited' => $netAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $wallet->fresh()->balance,
                    'payer'        => $data['virtual_bank_account_details']['payer_bank_account']['account_name'] ?? null,
                ],
            ]);
        });
    }

    /**
     * Withdrawal bank transfer completed.
     */
    private function onTransferSuccess(array $data, Request $request): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) return;

        $txn = Transaction::where('reference', $reference)
                          ->orWhere('provider_reference', $reference)
                          ->where('type', 'withdraw')
                          ->first();

        if (!$txn || $txn->isCompleted()) {
            AuditLog::create([
                'user_id'    => $txn->user_id ?? null,
                'event'      => $txn ? 'webhook.korapay.transfer_success.duplicate' : 'webhook.korapay.transfer_success.not_found',
                'ip_address' => $request->ip(),
                'new_values' => ['reference' => $reference],
            ]);
            return;
        }

        $txn->update(['status' => 'completed', 'completed_at' => now()]);

        $txn->user->notify(new \App\Notifications\WithdrawalCompletedNotification($txn));

        AuditLog::create([
            'user_id'        => $txn->user_id,
            'event'          => 'webhook.korapay.transfer_success.processed',
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'ip_address'     => $request->ip(),
            'new_values'     => [
                'reference'  => $reference,
                'amount'     => $data['amount']    ?? null,
                'fee'        => $data['fee']       ?? null,
                'narration'  => $data['narration'] ?? null,
            ],
        ]);
    }

    /**
     * Withdrawal bank transfer failed — refund wallet.
     */
    private function onTransferFailed(array $data, Request $request): void
    {
        $reference = $data['reference'] ?? null;
        if (!$reference) return;

        $txn = Transaction::where('reference', $reference)
                          ->orWhere('provider_reference', $reference)
                          ->where('type', 'withdraw')
                          ->first();

        if (!$txn || $txn->isFailed()) {
            AuditLog::create([
                'user_id'    => $txn->user_id ?? null,
                'event'      => $txn ? 'webhook.korapay.transfer_failed.duplicate' : 'webhook.korapay.transfer_failed.not_found',
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

        $txn->wallet->credit((float) $txn->amount);

        $txn->user->notify(new \App\Notifications\WithdrawalFailedNotification($txn));

        AuditLog::create([
            'user_id'        => $txn->user_id,
            'event'          => 'webhook.korapay.transfer_failed.processed',
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'ip_address'     => $request->ip(),
            'new_values'     => [
                'reference'  => $reference,
                'amount'     => $data['amount']  ?? null,
                'message'    => $data['message'] ?? null,
                'refunded'   => true,
            ],
        ]);
    }
}