<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessBuyOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(private readonly Transaction $transaction) {}

    public function handle(): void
    {
        $txn = $this->transaction->fresh();
        if (!$txn || $txn->isCompleted() || $txn->isFailed()) return;

        try {
            // Send crypto to user's wallet address via Breet payout API
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.breet.api_key'),
                'Content-Type'  => 'application/json',
            ])->post(config('services.breet.base_url') . '/crypto/send', [
                'asset'          => strtolower($txn->crypto_asset),
                'network'        => $txn->crypto_network,
                'amount'         => $txn->crypto_amount,
                'wallet_address' => $txn->deposit_address,
                'reference'      => $txn->reference,
            ]);

            if ($response->successful()) {
                $txn->update([
                    'status'          => 'completed',
                    'completed_at'    => now(),
                    'crypto_tx_hash'  => $response->json('data.tx_hash') ?? null,
                    'breet_reference' => $response->json('data.id')      ?? null,
                ]);

                $txn->user->notify(new \App\Notifications\BuyCompletedNotification($txn));
            } else {
                throw new \Exception($response->json('message') ?? 'Buy order failed.');
            }

        } catch (\Exception $e) {
            Log::error('ProcessBuyOrder failed', ['txn_id' => $txn->id, 'error' => $e->getMessage()]);

            if ($this->attempts() >= $this->tries) {
                // Refund wallet
                $txn->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => $e->getMessage()]);
                $txn->wallet->credit((float) $txn->amount);
                $txn->user->notify(new \App\Notifications\BuyFailedNotification($txn));
            } else {
                $this->release(now()->addMinutes(2));
            }
        }
    }
}
