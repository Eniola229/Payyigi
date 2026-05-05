<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use App\Jobs\ProcessBuyOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    /**
     * GET /api/v1/buy/rate?asset=USDT&network=trc20&ngn_amount=50000
     *
     * For buy: user pays NGN from wallet, receives crypto.
     * We charge MORE per unit (spread works in reverse vs sell).
     * e.g. market = ₦1500/USDT, spread 3.5% → user pays ₦1552.5/USDT
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'      => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network'    => 'required|string',
            'ngn_amount' => 'required|numeric|min:500',
        ]);

        try {
            $rateData      = $this->breet->getSellRate($request->asset);
            $marketRate    = (float) $rateData['rate'];
            $ngnAmount     = (float) $request->ngn_amount;
            $cryptoAmt     = $ngnAmount / $marketRate; // use market rate for tier check

            // Pick spread tier based on crypto amount equivalent
            $spreadPercent = $this->getSpreadPercent($cryptoAmt);

            // Buy rate: user pays MORE per unit
            $buyRate       = round($marketRate * (1 + ($spreadPercent / 100)), 2);

            // Platform fee on top of spread
            $platformFee   = round($ngnAmount * (config('payyigi.platform_fee_percent', 0.5) / 100), 2);
            $providerFee   = round($ngnAmount * 0.005, 2); // Breet's 0.5%
            $totalFee      = round($platformFee + $providerFee, 2);

            $ngnAfterFees  = round($ngnAmount - $totalFee, 2);
            $cryptoOut     = round($ngnAfterFees / $buyRate, 8);

            return response()->json([
                'data' => [
                    'asset'          => strtoupper($request->asset),
                    'network'        => $request->network,
                    'ngn_amount'     => $ngnAmount,
                    'rate'           => $buyRate,
                    'provider_fee'   => $providerFee,
                    'platform_fee'   => $platformFee,
                    'total_fee'      => $totalFee,
                    'crypto_amount'  => max(0, $cryptoOut),
                    'spread_percent' => $spreadPercent,
                    'rate_valid_for' => config('payyigi.rate_lock_seconds', 60) . ' seconds',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/buy
     *
     * Flow:
     * 1. Deduct NGN from user wallet immediately
     * 2. Create transaction record
     * 3. Dispatch ProcessBuyOrder job
     * 4. Job calls Breet to send crypto to user's external wallet address
     * 5. On success → mark completed, notify user
     * 6. On failure → refund wallet, notify user
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'          => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network'        => 'required|string',
            'ngn_amount'     => 'required|numeric|min:500',
            'wallet_address' => 'required|string|min:10|max:200',
        ]);

        $user      = $request->user();
        $wallet    = $user->wallet;
        $ngnAmount = (float) $request->ngn_amount;

        if (!$wallet->hasSufficientBalance($ngnAmount)) {
            return response()->json([
                'message'           => 'Insufficient wallet balance.',
                'available_balance' => $wallet->getAvailableBalance(),
            ], 422);
        }

        try {
            $rateData      = $this->breet->getSellRate($request->asset);
            $marketRate    = (float) $rateData['rate'];
            $cryptoEquiv   = $ngnAmount / $marketRate;
            $spreadPercent = $this->getSpreadPercent($cryptoEquiv);
            $buyRate       = round($marketRate * (1 + ($spreadPercent / 100)), 2);
            $platformFee   = round($ngnAmount * (config('payyigi.platform_fee_percent', 0.5) / 100), 2);
            $providerFee   = round($ngnAmount * 0.005, 2);
            $totalFee      = round($platformFee + $providerFee, 2);
            $ngnAfterFees  = round($ngnAmount - $totalFee, 2);
            $cryptoOut     = round($ngnAfterFees / $buyRate, 8);

            $transaction = DB::transaction(function () use (
                $user, $wallet, $request,
                $ngnAmount, $buyRate, $cryptoOut,
                $platformFee, $providerFee, $totalFee,
                $marketRate, $spreadPercent
            ) {
                $balanceBefore = (float) $wallet->balance;
                $wallet->debit($ngnAmount);

                return Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $wallet->id,
                    'type'           => 'buy',
                    'entry_type'     => 'debit',
                    'currency'       => 'NGN',
                    'amount'         => $ngnAmount,
                    'fee'            => $platformFee,
                    'provider_fee'   => $providerFee,
                    'net_amount'     => $ngnAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $wallet->fresh()->balance,
                    'crypto_asset'   => strtoupper($request->asset),
                    'crypto_network' => $request->network,
                    'crypto_amount'  => $cryptoOut,
                    'rate'           => $buyRate,
                    'deposit_address'=> $request->wallet_address, // user's receiving address
                    'status'         => 'processing',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                    'rate_locked_at' => now(),
                    'rate_expires_at'=> now()->addSeconds(config('payyigi.rate_lock_seconds', 60)),
                    'metadata'       => [
                        'market_rate'    => $marketRate,
                        'spread_percent' => $spreadPercent,
                        'total_fee'      => $totalFee,
                    ],
                ]);
            });

            // Dispatch job to send crypto via Breet
            ProcessBuyOrder::dispatch($transaction);

            AuditLog::record('transaction.buy_initiated', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'asset'          => $transaction->crypto_asset,
                    'crypto_amount'  => $transaction->crypto_amount,
                    'ngn_spent'      => $transaction->amount,
                    'reference'      => $transaction->reference,
                ],
            ]);

            return response()->json([
                'message' => 'Buy order placed. Crypto will be sent to your wallet address shortly.',
                'data'    => [
                    'reference'      => $transaction->reference,
                    'asset'          => $transaction->crypto_asset,
                    'network'        => $transaction->crypto_network,
                    'crypto_amount'  => $transaction->crypto_amount,
                    'ngn_spent'      => $transaction->amount,
                    'provider_fee'   => $providerFee,
                    'platform_fee'   => $platformFee,
                    'total_fee'      => $totalFee,
                    'rate'           => $transaction->rate,
                    'wallet_address' => $transaction->deposit_address,
                    'status'         => $transaction->status,
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
                ->where('type', 'buy')
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