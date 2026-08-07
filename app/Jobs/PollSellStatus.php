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

        if (empty($txn->breet_wallet_id)) {
            Log::warning('PollSellStatus: breet_wallet_id not set — wallet was never stored', [
                'reference' => $txn->reference,
            ]);
            return;
        }

        try {
            // ── PATH A: Breet transaction ID already known ─────────────────────
            if (!empty($txn->provider_reference)) {
                $data   = $breet->getTransaction($txn->provider_reference);
                $status = $data['status'] ?? 'pending';

                Log::info('PollSellStatus: polled Breet transaction', [
                    'reference'    => $txn->reference,
                    'breet_status' => $status,
                ]);

                if ($status === 'completed') {
                    self::markCompleted($txn, $data, $breet);
                    return;
                }

                if ($status === 'failed') {
                    self::markFailed($txn, ['reason' => $data['reason'] ?? 'Transaction failed.']);
                    return;
                }

                $this->release(30);
                return;
            }

            // ── PATH B: No Breet transaction ID yet ────────────────────────────
            $wallet = $breet->getWallet($txn->breet_wallet_id);

            Log::info('PollSellStatus: wallet active, awaiting deposit', [
                'reference'      => $txn->reference,
                'breet_wallet_id'=> $txn->breet_wallet_id,
                'breet_vault_id' => $txn->breet_vault_id,
                'address'        => $wallet['address'] ?? null,
            ]);

            $this->release(30);

        } catch (\Exception $e) {
            Log::error('PollSellStatus: error', [
                'reference' => $txn->reference,
                'error'     => $e->getMessage(),
            ]);
            $this->release(30);
        }
    }

    /**
     * Mark a sell transaction as completed.
     *
     * Payout is normally NOT triggered here. Per-address auto-settlement is
     * enabled on the Breet wallet at initiation (see SellController::initiate()),
     * so Breet itself converts the crypto, deducts your configured markup, and
     * pays the linked bank account directly as part of settlement — by the
     * time this fires, the money has usually already landed.
     *
     * SAFETY NET: if auto-settlement could NOT be confirmed at initiation
     * (metadata['auto_settlement_confirmed'] === false — e.g. updateWalletBank()
     * failed with "wallet does not have a bank"), we can't trust Breet paid
     * the customer automatically. In that case this falls back to a manual
     * payout, and flags the transaction for manual review either way.
     */
    public static function markCompleted(Transaction $txn, array $data, BreetService $breet): void
    {
        // ── RAW FIGURES FROM BREET (kept only for audit trail) ─────────────
        $grossAmountSettled = $data['amountSettled']  ?? $data['amount']         ?? 0;
        $feeAmount           = $data['feeAmountInUsd'] ?? $data['feeAmount']      ?? 0;
        $rate                = $data['settlementRate'] ?? $data['rate']           ?? 0;
        $cryptoReceived      = $data['cryptoAmount']   ?? $data['cryptoReceived'] ?? $txn->crypto_amount;
        $txHash              = $data['txHash']                                     ?? null;
        $markupPercent       = $data['markupPercent']                              ?? null;
        $markupAmount        = $data['markupAmount']                               ?? null;
        $flagFeeUSD          = $data['flagFeeUSD']                                 ?? 0;

        // ── DEDUCT PLATFORM FEE IMMEDIATELY — everything below this line ──
        // uses $amountSettled as the NET figure. Fee % is the one snapshotted
        // at initiation (metadata['platform_fee_percent']), never re-read from
        // config here, so a later config change can't alter what was promised.
        $platformFeePercent = (float) (
            $txn->metadata['platform_fee_percent']
            ?? config('payyigi.platform_fee_percent', 1.0)
        );
        $platformFeeActual = round($grossAmountSettled * $platformFeePercent / 100, 2);
        $amountSettled      = round($grossAmountSettled - $platformFeeActual, 2); // ← NET from here on

        $autoSettlementConfirmed = (bool) ($txn->metadata['auto_settlement_confirmed'] ?? false);
        $manualPayoutTriggered   = false;
        $manualPayoutError       = null;

        if (!$autoSettlementConfirmed && $amountSettled > 0 && empty($txn->provider_payout_id)) {
            Log::critical('markCompleted: auto-settlement was not confirmed at initiation — attempting manual payout fallback so the customer gets paid', [
                'reference' => $txn->reference,
                'wallet_id' => $txn->breet_wallet_id,
            ]);

            try {
                $breetBankId = $breet->resolveBankIdFromCode($txn->bank_code);

                if (!$breetBankId) {
                    throw new \Exception("Could not resolve Breet bank id for bank code '{$txn->bank_code}'.");
                }

                $breet->verifyBankAccount($breetBankId, $txn->account_number);
                $bank = $breet->addBank($breetBankId, $txn->account_number, "PayYigi sell #{$txn->reference}");

                $payoutResult = $breet->withdrawToBank(
                    savedBankId: $bank['id'],
                    amount: $amountSettled, // net — platform fee already stripped out above
                    externalId: $txn->reference,
                    narration: "PayYigi sell #{$txn->reference} (manual fallback)",
                );

                $txn->update([
                    'provider_payout_id'     => $payoutResult['id'] ?? null,
                    'provider_payout_status' => $payoutResult['status'] ?? 'pending',
                ]);

                $manualPayoutTriggered = true;

                Log::info('markCompleted: manual payout fallback succeeded', [
                    'reference'     => $txn->reference,
                    'payout_id'     => $payoutResult['id'] ?? null,
                    'gross_settled' => $grossAmountSettled,
                    'platform_fee'  => $platformFeeActual,
                    'net_paid_out'  => $amountSettled,
                ]);

                \App\Jobs\MonitorPayoutStatus::dispatch($txn->fresh())->delay(now()->addSeconds(30));
            } catch (\Exception $e) {
                $manualPayoutError = $e->getMessage();
                Log::critical('markCompleted: manual payout fallback FAILED — customer NOT yet paid, needs manual intervention now', [
                    'reference' => $txn->reference,
                    'amount'    => $amountSettled,
                    'error'     => $manualPayoutError,
                ]);
            }
        } elseif ($autoSettlementConfirmed) {
            // Breet's own auto-settlement already sent money directly to the
            // customer's bank BEFORE this code ever runs — it settled the
            // GROSS amount, not the net figure computed above. We cannot claw
            // that back after the fact. This is flagged, not silently fixed,
            // because fixing it means changing how settlement works upstream
            // (see note at the end of this method).
            Log::warning('markCompleted: auto-settlement path already paid gross amount — platform fee NOT captured on this transaction', [
                'reference'       => $txn->reference,
                'gross_settled'   => $grossAmountSettled,
                'fee_not_captured'=> $platformFeeActual,
            ]);
        }

        $txn->update([
            'status'                 => 'completed',
            'completed_at'           => now(),
            'amount'                 => $amountSettled,   // ← now NET everywhere
            'net_amount'             => $amountSettled,   // ← same net value, kept for compatibility
            'provider_fee'           => $feeAmount,
            'rate'                   => $rate,
            'crypto_tx_hash'         => $txHash,
            'provider_payout_status' => $manualPayoutTriggered
                ? ($txn->fresh()->provider_payout_status ?? 'pending')
                : ($autoSettlementConfirmed ? 'auto_settled' : 'needs_review'),
            'metadata'               => array_merge((array) ($txn->metadata ?? []), [
                'amount_settled_gross'                => $grossAmountSettled,
                'amount_settled_net'                  => $amountSettled,
                'platform_fee_percent_at_settlement'  => $platformFeePercent,
                'platform_fee_actual'                 => $platformFeeActual,
                'breet_fee_usd'                        => $feeAmount,
                'settlement_rate'                      => $rate,
                'crypto_received'                      => $cryptoReceived,
                'tx_hash'                               => $txHash,
                'markup_percent'                        => $markupPercent,
                'markup_amount'                         => $markupAmount,
                'flag_fee_usd'                           => $flagFeeUSD,
                'manual_payout_triggered'                => $manualPayoutTriggered,
                'manual_payout_error'                    => $manualPayoutError,
            ]),
        ]);

        AuditLog::record('transaction.sell_completed', [
            'user_id'        => $txn->user_id,
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'new_values'     => [
                'reference'               => $txn->reference,
                'amount_settled_gross'    => $grossAmountSettled,
                'platform_fee'            => $platformFeeActual,
                'amount_settled_net'      => $amountSettled,
                'bank'                    => $txn->account_number,
                'tx_hash'                 => $txHash,
                'markup_percent'          => $markupPercent,
                'markup_amount'           => $markupAmount,
                'manual_payout_triggered' => $manualPayoutTriggered,
            ],
        ]);

        $txn->user->notify(new \App\Notifications\TransactionCompletedNotification($txn));

        if (!$autoSettlementConfirmed) {
            Log::critical('Sell completed via UNCONFIRMED auto-settlement path — flagged for manual review', [
                'reference'               => $txn->reference,
                'manual_payout_triggered' => $manualPayoutTriggered,
                'manual_payout_error'     => $manualPayoutError,
            ]);
        }

        Log::info('Sell completed', [
            'reference'                 => $txn->reference,
            'gross_settled'             => $grossAmountSettled,
            'platform_fee'              => $platformFeeActual,
            'net_settled'               => $amountSettled,
            'tx_hash'                   => $txHash,
            'markup_percent'            => $markupPercent,
            'markup_amount'             => $markupAmount,
            'auto_settlement_confirmed' => $autoSettlementConfirmed,
            'manual_payout_triggered'   => $manualPayoutTriggered,
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