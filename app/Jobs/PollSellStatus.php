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
     * Mark a sell transaction as completed, and trigger the bank payout
     * if it hasn't been triggered yet (idempotent via provider_payout_id).
     */
    public static function markCompleted(Transaction $txn, array $data, BreetService $breet): void
    {
        $amountSettled  = $data['amountSettled']  ?? $data['amount']         ?? 0;
        $feeAmount      = $data['feeAmountInUsd'] ?? $data['feeAmount']      ?? 0;
        $rate           = $data['settlementRate'] ?? $data['rate']           ?? 0;
        $cryptoReceived = $data['cryptoAmount']   ?? $data['cryptoReceived'] ?? $txn->crypto_amount;
        $txHash         = $data['txHash']                                     ?? null;
        $markupPercent  = $data['markupPercent']                              ?? null;
        $markupAmount   = $data['markupAmount']                               ?? null;
        $flagFeeUSD     = $data['flagFeeUSD']                                 ?? 0;

        // ── TRIGGER PAYOUT IF NOT ALREADY DONE ──────────────────────────────
        // This is the single choke point for payout creation — whichever path
        // (webhook or poll job) detects completion first triggers it exactly once.
        if (empty($txn->provider_payout_id)) {
            if ($amountSettled <= 0) {
                Log::error('markCompleted: cannot trigger payout, settlement amount is 0', [
                    'reference' => $txn->reference,
                ]); 
            } else {
            try {
                $breetBankId = $breet->resolveBankIdFromCode($txn->bank_code);

                if (!$breetBankId) {
                    throw new \Exception("Could not resolve Breet bank id for bank code '{$txn->bank_code}'.");
                }

                // Verify first — Breet requires this before addBank() will accept the account
                $verified = $breet->verifyBankAccount($breetBankId, $txn->account_number);

                $bank = $breet->addBank($breetBankId, $txn->account_number, "PayYigi sell #{$txn->reference}");
                    $payoutResult = $breet->withdrawToBank(
                        savedBankId: $bank['id'],
                        amount: $amountSettled,
                        externalId: $txn->reference,
                        narration: "PayYigi sell order #{$txn->reference}",
                    );
                    $txn->update([
                        'provider_payout_id'     => $payoutResult['id'] ?? null,
                        'provider_payout_status' => $payoutResult['status'] ?? 'pending',
                    ]);

                    Log::info('Payout triggered from markCompleted', [
                        'reference' => $txn->reference,
                        'payout_id' => $payoutResult['id'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('markCompleted: failed to trigger payout', [
                        'reference' => $txn->reference,
                        'error'     => $e->getMessage(),
                    ]);
                    $txn->update(['provider_payout_status' => 'pending_retry']);
                }
            }
        }

        $txn->update([
            'status'         => 'completed',
            'completed_at'   => now(),
            'amount'         => $amountSettled,
            'net_amount'     => $amountSettled,
            'provider_fee'   => $feeAmount,
            'rate'           => $rate,
            'crypto_tx_hash' => $txHash,
            'metadata'       => array_merge((array) ($txn->metadata ?? []), [
                'amount_settled'  => $amountSettled,
                'breet_fee_usd'   => $feeAmount,
                'settlement_rate' => $rate,
                'crypto_received' => $cryptoReceived,
                'tx_hash'         => $txHash,
                'markup_percent'  => $markupPercent,
                'markup_amount'   => $markupAmount,
                'flag_fee_usd'    => $flagFeeUSD,
            ]),
        ]);

        AuditLog::record('transaction.sell_completed', [
            'user_id'        => $txn->user_id,
            'auditable_type' => Transaction::class,
            'auditable_id'   => $txn->id,
            'new_values'     => [
                'reference'      => $txn->reference,
                'amount_settled' => $amountSettled,
                'bank'           => $txn->account_number,
                'tx_hash'        => $txHash,
            ],
        ]);

        $txn->user->notify(new \App\Notifications\TransactionCompletedNotification($txn));

        // Start monitoring the payout now that it's (hopefully) been created.
        $fresh = $txn->fresh();
        if (!empty($fresh->provider_payout_id)) {
            \App\Jobs\MonitorPayoutStatus::dispatch($fresh)->delay(now()->addSeconds(30));
        }

        Log::info('Sell completed', [
            'reference'      => $txn->reference,
            'amount_settled' => $amountSettled,
            'tx_hash'        => $txHash,
            'payout_id'      => $fresh->provider_payout_id,
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