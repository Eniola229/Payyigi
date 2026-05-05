<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SwapController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    /**
     * GET /api/v1/swap/rate?from=BTC&to=USDT&amount=0.01
     *
     * Swap rate uses NGN as the bridge:
     * 1. Get NGN value of FROM asset at market rate
     * 2. Apply spread (double — selling from and buying to)
     * 3. Divide by TO asset rate to get crypto out
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'from'   => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'to'     => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE|different:from',
            'amount' => 'required|numeric|min:0.000001',
        ]);

        try {
            $fromRateData  = $this->breet->getSellRate($request->from);
            $toRateData    = $this->breet->getSellRate($request->to);

            $fromRate      = (float) $fromRateData['rate'];
            $toRate        = (float) $toRateData['rate'];
            $fromAmount    = (float) $request->amount;

            $spreadPercent = $this->getSpreadPercent($fromAmount);

            // NGN value of from asset after spread
            $ngnValue      = round($fromAmount * $fromRate, 2);
            $ngnAfterSpread= round($ngnValue * (1 - ($spreadPercent / 100)), 2);

            // Breet's 0.5% fee on NGN value
            $providerFee   = round($ngnAfterSpread * 0.005, 2);
            $platformFee   = round($ngnAfterSpread * (config('payyigi.platform_fee_percent', 0.5) / 100), 2);
            $totalFee      = round($providerFee + $platformFee, 2);

            $ngnAfterFees  = round($ngnAfterSpread - $totalFee, 2);
            $toAmount      = round($ngnAfterFees / $toRate, 8);

            return response()->json([
                'data' => [
                    'from'           => strtoupper($request->from),
                    'to'             => strtoupper($request->to),
                    'from_amount'    => $fromAmount,
                    'to_amount'      => max(0, $toAmount),
                    'from_rate_ngn'  => $fromRate,
                    'to_rate_ngn'    => $toRate,
                    'ngn_value'      => $ngnValue,
                    'provider_fee'   => $providerFee,
                    'platform_fee'   => $platformFee,
                    'total_fee'      => $totalFee,
                    'spread_percent' => $spreadPercent,
                    'rate_valid_for' => config('payyigi.rate_lock_seconds', 60) . ' seconds',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/swap
     *
     * Flow:
     * 1. Calculate rates and fees
     * 2. Create transaction record
     * 3. Call Breet createSwapOrder → get deposit address for FROM asset
     * 4. User sends FROM crypto to deposit address
     * 5. PollSellStatus job polls for completion
     * 6. When complete → no wallet credit (crypto-to-crypto, no NGN)
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'from'           => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'from_network'   => 'required|string',
            'to'             => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE|different:from',
            'amount'         => 'required|numeric|min:0.000001',
            'wallet_address' => 'required|string|min:10|max:200', // where TO asset will be sent
        ]);

        $user = $request->user();

        try {
            $fromRateData  = $this->breet->getSellRate($request->from);
            $toRateData    = $this->breet->getSellRate($request->to);

            $fromRate      = (float) $fromRateData['rate'];
            $toRate        = (float) $toRateData['rate'];
            $fromAmount    = (float) $request->amount;
            $spreadPercent = $this->getSpreadPercent($fromAmount);

            $ngnValue      = round($fromAmount * $fromRate, 2);
            $ngnAfterSpread= round($ngnValue * (1 - ($spreadPercent / 100)), 2);
            $providerFee   = round($ngnAfterSpread * 0.005, 2);
            $platformFee   = round($ngnAfterSpread * (config('payyigi.platform_fee_percent', 0.5) / 100), 2);
            $totalFee      = round($providerFee + $platformFee, 2);
            $ngnAfterFees  = round($ngnAfterSpread - $totalFee, 2);
            $toAmount      = round($ngnAfterFees / $toRate, 8);
            $spreadAmount  = round($ngnValue - $ngnAfterSpread, 2);

            $transaction = DB::transaction(function () use (
                $user, $request, $fromAmount, $toAmount,
                $fromRate, $ngnValue, $spreadAmount,
                $platformFee, $providerFee, $totalFee, $spreadPercent
            ) {
                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'swap',
                    'entry_type'     => 'debit',
                    'currency'       => 'NGN',
                    'amount'         => $ngnValue,
                    'fee'            => $platformFee,
                    'provider_fee'   => $providerFee,
                    'net_amount'     => $ngnValue,
                    'spread_amount'  => $spreadAmount,
                    'crypto_asset'   => strtoupper($request->from),
                    'crypto_network' => $request->from_network,
                    'crypto_amount'  => $fromAmount,
                    'swap_to_asset'  => strtoupper($request->to),
                    'swap_to_amount' => $toAmount,
                    'rate'           => $fromRate,
                    'status'         => 'awaiting_crypto',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                    'rate_locked_at' => now(),
                    'rate_expires_at'=> now()->addSeconds(config('payyigi.rate_lock_seconds', 60)),
                    'metadata'       => [
                        'spread_percent'  => $spreadPercent,
                        'total_fee'       => $totalFee,
                        'to_wallet'       => $request->wallet_address,
                    ],
                ]);

                // Call Breet swap — get deposit address for FROM asset
                $breetOrder = app(BreetService::class)->createSwapOrder(
                    fromAsset:           $request->from,
                    fromNetwork:         $request->from_network,
                    toAsset:             $request->to,
                    amount:              $fromAmount,
                    reference:           $txn->reference,
                    destinationAddress:  $request->wallet_address,
                );

                $txn->update([
                    'provider_order_id'  => $breetOrder['id']        ?? null,
                    'provider_reference' => $breetOrder['reference']  ?? null,
                    'deposit_address'    => $breetOrder['address']    ?? null,
                    'provider_response'  => $breetOrder,
                ]);

                return $txn;
            });

            // Poll for swap completion
            \App\Jobs\PollSellStatus::dispatch($transaction)->delay(now()->addSeconds(30));

            AuditLog::record('transaction.swap_initiated', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'from'       => $transaction->crypto_asset,
                    'to'         => $transaction->swap_to_asset,
                    'from_amount'=> $transaction->crypto_amount,
                    'to_amount'  => $transaction->swap_to_amount,
                    'reference'  => $transaction->reference,
                ],
            ]);

            return response()->json([
                'message' => 'Swap order created. Send your crypto to the address below.',
                'data'    => [
                    'reference'          => $transaction->reference,
                    'from'               => $transaction->crypto_asset,
                    'from_network'       => $transaction->crypto_network,
                    'amount_to_send'     => $transaction->crypto_amount,
                    'deposit_address'    => $transaction->deposit_address,
                    'to'                 => $transaction->swap_to_asset,
                    'to_amount'          => $transaction->swap_to_amount,
                    'destination_wallet' => $request->wallet_address,
                    'provider_fee'       => $providerFee,
                    'platform_fee'       => $platformFee,
                    'total_fee'          => $totalFee,
                    'status'             => $transaction->status,
                    'expires_at'         => $transaction->rate_expires_at,
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
                ->where('type', 'swap')
                ->latest()
                ->paginate(20),
        ]);
    }

    private function getSpreadPercent(float $cryptoAmount): float
    {
        foreach (config('payyigi.platform_fee_tiers') as $tier) {
            if ($cryptoAmount >= $tier['min'] && (is_null($tier['max']) || $cryptoAmount < $tier['max'])) {
                return $tier['percent'];
            }
        }
        return 3.5;
    }
}