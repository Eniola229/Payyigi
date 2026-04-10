<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    /**
     * GET /api/v1/buy/rate?asset=USDT&network=trc20&ngn_amount=50000
     * Returns how much crypto user will receive for a given NGN amount
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'      => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network'    => 'required|string',
            'ngn_amount' => 'required|numeric|min:500',
        ]);

        try {
            $rateData   = $this->breet->getRate($request->asset);
            $marketRate = (float) $rateData['rate']; // NGN per 1 unit
            // For buy, we charge MORE (user pays more NGN per unit = spread works in reverse)
            $spreadPct  = config('payyigi.spread_percent', 4);
            $buyRate    = round($marketRate * (1 + ($spreadPct / 100)), 2);
            $ngnAmount  = (float) $request->ngn_amount;
            $cryptoOut  = round($ngnAmount / $buyRate, 8);

            return response()->json([
                'data' => [
                    'asset'          => strtoupper($request->asset),
                    'network'        => $request->network,
                    'ngn_amount'     => $ngnAmount,
                    'rate'           => $buyRate,
                    'crypto_amount'  => $cryptoOut,
                    'spread_percent' => $spreadPct,
                    'rate_valid_for' => config('payyigi.rate_lock_seconds', 60) . ' seconds',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/buy
     * Deducts NGN from wallet, sends crypto to user's provided wallet address
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'           => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network'         => 'required|string',
            'ngn_amount'      => 'required|numeric|min:500',
            'wallet_address'  => 'required|string|min:10|max:200',
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
            $rateData   = $this->breet->getRate($request->asset);
            $marketRate = (float) $rateData['rate'];
            $spreadPct  = config('payyigi.spread_percent', 4);
            $buyRate    = round($marketRate * (1 + ($spreadPct / 100)), 2);
            $cryptoOut  = round($ngnAmount / $buyRate, 8);
            $spreadAmt  = round($ngnAmount - ($ngnAmount / (1 + ($spreadPct / 100))), 2);
            $breetFee   = round($ngnAmount * 0.005, 2);

            $transaction = DB::transaction(function () use (
                $user, $wallet, $request,
                $ngnAmount, $buyRate, $marketRate,
                $cryptoOut, $spreadAmt, $breetFee
            ) {
                $balanceBefore = (float) $wallet->balance;
                $wallet->debit($ngnAmount);

                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $wallet->id,
                    'type'           => 'buy',
                    'entry_type'     => 'debit',
                    'currency'       => 'NGN',
                    'amount'         => $ngnAmount,
                    'breet_fee'      => $breetFee,
                    'net_amount'     => $ngnAmount,
                    'spread_amount'  => $spreadAmt,
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
                    'metadata'       => ['market_rate' => $marketRate],
                ]);

                // Dispatch job to send crypto via Breet
                \App\Jobs\ProcessBuyOrder::dispatch($txn);

                return $txn;
            });

            AuditLog::record('transaction.buy_initiated', [
                'user_id'      => $user->id,
                'auditable_id' => $transaction->id,
            ]);

            return response()->json([
                'message' => 'Buy order placed. Crypto will be sent to your wallet address shortly.',
                'data'    => [
                    'reference'      => $transaction->reference,
                    'asset'          => $transaction->crypto_asset,
                    'network'        => $transaction->crypto_network,
                    'crypto_amount'  => $transaction->crypto_amount,
                    'ngn_deducted'   => $transaction->amount,
                    'wallet_address' => $transaction->deposit_address,
                    'rate'           => $transaction->rate,
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
                ->where('type', 'buy')->latest()->paginate(20),
        ]);
    }
}
PHPEOF