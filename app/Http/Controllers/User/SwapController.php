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
     * Returns how much of the target asset user will receive
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'from'    => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'to'      => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE|different:from',
            'amount'  => 'required|numeric|min:0.000001',
        ]);

        try {
            // Get NGN value of the FROM asset
            $fromRate   = (float) $this->breet->getRate($request->from)['rate'];
            $toRate     = (float) $this->breet->getRate($request->to)['rate'];
            $spreadPct  = config('payyigi.spread_percent', 4);

            $ngnValue   = (float) $request->amount * $fromRate;
            // Apply spread twice (once selling from, once buying to)
            $ngnAfterSpread = $ngnValue * (1 - ($spreadPct / 100));
            $toAmount   = round($ngnAfterSpread / $toRate, 8);

            return response()->json([
                'data' => [
                    'from'           => strtoupper($request->from),
                    'to'             => strtoupper($request->to),
                    'from_amount'    => (float) $request->amount,
                    'to_amount'      => $toAmount,
                    'from_rate_ngn'  => $fromRate,
                    'to_rate_ngn'    => $toRate,
                    'spread_percent' => $spreadPct,
                    'rate_valid_for' => config('payyigi.rate_lock_seconds', 60) . ' seconds',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/swap
     * Swap from_asset to to_asset. User provides wallet address for to_asset.
     * Flow: user sends from_asset crypto → Breet converts → sends to_asset to user's address
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'from'           => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'from_network'   => 'required|string',
            'to'             => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE|different:from',
            'to_network'     => 'required|string',
            'amount'         => 'required|numeric|min:0.000001',
            'wallet_address' => 'required|string|min:10|max:200', // destination wallet for to_asset
        ]);

        $user = $request->user();

        try {
            $fromRate      = (float) $this->breet->getRate($request->from)['rate'];
            $toRate        = (float) $this->breet->getRate($request->to)['rate'];
            $spreadPct     = config('payyigi.spread_percent', 4);
            $fromAmount    = (float) $request->amount;
            $ngnValue      = $fromAmount * $fromRate;
            $ngnAfterSpread= $ngnValue * (1 - ($spreadPct / 100));
            $toAmount      = round($ngnAfterSpread / $toRate, 8);
            $spreadAmt     = round($ngnValue - $ngnAfterSpread, 2);

            $transaction = DB::transaction(function () use (
                $user, $request, $fromAmount, $toAmount,
                $fromRate, $toRate, $ngnValue, $spreadAmt
            ) {
                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'swap',
                    'entry_type'     => 'debit',
                    'currency'       => 'NGN',
                    'amount'         => $ngnValue, // NGN equivalent for records
                    'net_amount'     => $ngnValue,
                    'spread_amount'  => $spreadAmt,
                    'crypto_asset'   => strtoupper($request->from),
                    'crypto_network' => $request->from_network,
                    'crypto_amount'  => $fromAmount,
                    'swap_to_asset'  => strtoupper($request->to),
                    'swap_to_amount' => $toAmount,
                    'rate'           => $fromRate,
                    'deposit_address'=> $request->wallet_address,
                    'status'         => 'awaiting_crypto',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                    'rate_locked_at' => now(),
                    'rate_expires_at'=> now()->addSeconds(config('payyigi.rate_lock_seconds', 60)),
                    'metadata'       => [
                        'to_network' => $request->to_network,
                        'to_rate'    => $toRate,
                    ],
                ]);

                // Create sell order on Breet for the FROM asset
                // Breet will handle conversion and send TO asset to wallet_address
                $breetOrder = app(BreetService::class)->createSellOrder(
                    asset:       $request->from,
                    network:     $request->from_network,
                    amount:      $fromAmount,
                    bankAccount: [], // swap doesn't need bank account — future Breet swap API
                    reference:   $txn->reference,
                );

                $txn->update([
                    'breet_order_id'  => $breetOrder['id']        ?? null,
                    'breet_reference' => $breetOrder['reference'] ?? null,
                    'deposit_address' => $breetOrder['address']   ?? $request->wallet_address,
                    'breet_response'  => $breetOrder,
                ]);

                return $txn;
            });

            AuditLog::record('transaction.swap_initiated', [
                'user_id'      => $user->id,
                'auditable_id' => $transaction->id,
            ]);

            return response()->json([
                'message' => 'Send your crypto to the address below to complete the swap.',
                'data'    => [
                    'reference'       => $transaction->reference,
                    'from'            => $transaction->crypto_asset,
                    'from_network'    => $transaction->crypto_network,
                    'amount_to_send'  => $transaction->crypto_amount,
                    'deposit_address' => $transaction->deposit_address,
                    'to'              => $transaction->swap_to_asset,
                    'to_amount'       => $transaction->swap_to_amount,
                    'destination_wallet' => $request->wallet_address,
                    'status'          => $transaction->status,
                    'expires_at'      => $transaction->rate_expires_at,
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
                ->where('type', 'swap')->latest()->paginate(20),
        ]);
    }
}
