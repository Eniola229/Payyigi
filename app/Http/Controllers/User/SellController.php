<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreetAsset;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    /**
     * GET /api/v1/sell/assets
     * List all supported sell assets (from breet_assets table).
     * Frontend uses this to populate the asset/network picker.
     */
    public function assets(): JsonResponse
    {
        $assets = BreetAsset::where('is_active', true)
            ->orderBy('symbol')
            ->get(['id', 'symbol', 'name', 'network', 'icon', 'minimum']);

        return response()->json(['data' => $assets]);
    }

    /**
     * GET /api/v1/sell/rate?asset=USDT&network=Tron&amount=100&currency=ngn
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'    => 'required|string',
            'network'  => 'required|string',
            'amount'   => 'required|numeric|min:0.000001',
            'currency' => 'sometimes|string|in:ngn,ghs',
        ]);

        try {
            $breetAsset = BreetAsset::resolve($request->asset, $request->network);
            $rateData   = $this->breet->getRateCalculator(
                $breetAsset->id,
                (float) $request->amount,
                $request->currency ?? 'ngn'
            );

            // Breet returns NGNAmount for the given amountInUSD,
            // plus the rate (NGN per USD) and cryptoAmount.
            // Fees + your markup % are already baked in by Breet.
            return response()->json([
                'data' => [
                    'asset'         => strtoupper($request->asset),
                    'network'       => $request->network,
                    'amount'        => (float) $request->amount,
                    'currency'      => strtoupper($request->currency ?? 'NGN'),
                    'rate'          => $rateData['rate'],         // NGN per USD
                    'ngn_amount'    => $rateData['NGNAmount'],    // estimated NGN payout
                    'crypto_amount' => $rateData['cryptoAmount'], // crypto equivalent of $amount USD
                    'destination'   => 'your bank account directly',
                    'note'          => 'Final amount is determined by Breet at settlement time.',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/sell
     *
     * Flow:
     * 1. Resolve Breet asset ID from ticker + network
     * 2. Generate a permanent deposit address (once per user per asset — reused after)
     * 3. Breet monitors blockchain; when crypto arrives it converts + settles to user's bank
     * 4. Your markup % (set on Breet dashboard) is deducted automatically
     * 5. We record the transaction for history — NO wallet credit needed
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'           => 'required|string',
            'network'         => 'required|string',
            'amount'          => 'required|numeric|min:0.000001',
            'currency'        => 'sometimes|string|in:ngn,ghs',
            'bank_account_id' => 'required|uuid|exists:bank_accounts,id',
        ]);

        $user        = $request->user();
        $bankAccount = $user->bankAccounts()->findOrFail($request->bank_account_id);

        try {
            $breetAsset = BreetAsset::resolve($request->asset, $request->network);
            $cryptoAmt  = (float) $request->amount;
            $rateData   = $this->breet->getRateCalculator(
                $breetAsset->id,
                $cryptoAmt,
                $request->currency ?? 'ngn'
            );

            $transaction = DB::transaction(function () use (
                $user, $bankAccount, $request, $breetAsset, $cryptoAmt, $rateData
            ) {
                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'sell',
                    'entry_type'     => 'debit',
                    'currency'       => strtoupper($request->currency ?? 'NGN'),
                    // amount/net_amount updated from Breet's actual settlement on completion
                    'amount'         => 0,
                    'net_amount'     => 0,
                    'crypto_asset'   => strtoupper($request->asset),
                    'crypto_network' => $request->network,
                    'crypto_amount'  => $cryptoAmt,
                    'rate'           => $rateData['rate'] ?? 0,
                    // Bank account — Breet settles NGN here directly
                    'bank_account_id'=> $bankAccount->id,
                    'bank_name'      => $bankAccount->bank_name,
                    'bank_code'      => $bankAccount->bank_code,
                    'account_number' => $bankAccount->account_number,
                    'account_name'   => $bankAccount->account_name,
                    'status'         => 'awaiting_crypto',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                    'rate_locked_at' => now(),
                    'rate_expires_at'=> now()->addSeconds((int) config('payyigi.rate_lock_seconds', 60)),
                    'metadata'       => [
                        'estimated_ngn'  => $rateData['NGNAmount']    ?? 0,
                        'crypto_amount'  => $rateData['cryptoAmount'] ?? $cryptoAmt,
                        'breet_asset_id' => $breetAsset->id,
                    ],
                ]);

                // Generate permanent deposit address on Breet with bank linked for auto-settlement.
                // Breet will deduct your markup % (configured on Breet dashboard) automatically.
                $breetWallet = app(BreetService::class)->generateDepositAddress(
                    assetId:       $breetAsset->id,
                    label:         "user-{$user->id}-{$breetAsset->symbol}",
                    bankId:        $bankAccount->bank_code,   // Breet bankId = your bank_code
                    accountNumber: $bankAccount->account_number,
                    narration:     'PayYigi sell order',
                );

                $txn->update([
                    'provider_order_id' => $breetWallet['id']      ?? null,
                    'deposit_address'   => $breetWallet['address']  ?? null,
                    'provider_response' => $breetWallet,
                ]);

                return $txn;
            });

            \App\Jobs\PollSellStatus::dispatch($transaction)->delay(now()->addSeconds(30));

            AuditLog::record('transaction.sell_initiated', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'asset'      => $transaction->crypto_asset,
                    'network'    => $transaction->crypto_network,
                    'amount'     => $transaction->crypto_amount,
                    'bank'       => $transaction->account_number,
                    'reference'  => $transaction->reference,
                ],
            ]);

            return response()->json([
                'message' => 'Sell order created. Send your crypto to the address below. NGN will be sent directly to your bank account.',
                'data'    => [
                    'reference'       => $transaction->reference,
                    'asset'           => $transaction->crypto_asset,
                    'network'         => $transaction->crypto_network,
                    'amount_to_send'  => $transaction->crypto_amount,
                    'deposit_address' => $transaction->deposit_address,
                    'estimated_ngn'   => $rateData['NGNAmount'] ?? 0,
                    'destination'     => [
                        'bank_name'      => $transaction->bank_name,
                        'account_number' => $transaction->account_number,
                        'account_name'   => $transaction->account_name,
                    ],
                    'note'      => 'We will apply your configured markup and settle net NGN directly to your bank.',
                    'status'    => $transaction->status,
                    'expires_at'=> $transaction->rate_expires_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->transactions()
                ->where('type', 'sell')
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $txn = $request->user()
            ->transactions()
            ->where('reference', $reference)
            ->where('type', 'sell')
            ->firstOrFail();

        return response()->json(['data' => $txn]);
    }
}