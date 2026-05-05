<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use App\Jobs\PollSellStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellController extends Controller
{
    public function __construct(private readonly BreetService $breet) {}

    /**
     * GET /api/v1/sell/rate?asset=USDT&network=trc20&amount=100
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'   => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network' => 'required|string',
            'amount'  => 'required|numeric|min:0.000001',
        ]);

        try {
            $rateData = $this->breet->getSellRate($request->asset);
            $fees     = $this->breet->calculateFees((float) $request->amount, $rateData);

            return response()->json([
                'data' => [
                    'asset'          => strtoupper($request->asset),
                    'network'        => $request->network,
                    'crypto_amount'  => (float) $request->amount,
                    'market_rate'    => $fees['market_rate'],
                    'rate'           => $fees['display_rate'],
                    'gross_ngn'      => $fees['gross_ngn'],
                    'provider_fee'   => $fees['provider_fee'],
                    'platform_fee'   => $fees['platform_fee'],
                    'total_fee'      => $fees['total_fee'],
                    'ngn_amount'     => $fees['net_ngn'],
                    'spread_percent' => $fees['spread_percent'],
                    'destination'    => 'wallet balance',
                    'rate_valid_for' => config('payyigi.rate_lock_seconds', 60) . ' seconds',
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
     * 1. Get fresh rate from Breet + calculate fees
     * 2. Create transaction record
     * 3. Call Breet → get unique deposit address for this order
     * 4. Return deposit address to user (they send crypto here)
     * 5. PollSellStatus job polls Breet every 30s
     * 6. When Breet confirms → credit user wallet
     *
     * Breet pays NGN to our COMPANY bank account.
     * We credit user's PayYigi wallet when confirmed.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'   => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network' => 'required|string',
            'amount'  => 'required|numeric|min:0.000001',
        ]);

        $user = $request->user();

        try {
            $rateData  = $this->breet->getSellRate($request->asset);
            $cryptoAmt = (float) $request->amount;
            $fees      = $this->breet->calculateFees($cryptoAmt, $rateData);

            $transaction = DB::transaction(function () use ($user, $request, $cryptoAmt, $fees) {

                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'sell',
                    'entry_type'     => 'credit',
                    'currency'       => 'NGN',
                    'amount'         => $fees['net_ngn'],
                    'fee'            => $fees['platform_fee'],
                    'provider_fee'   => $fees['provider_fee'],
                    'net_amount'     => $fees['net_ngn'],
                    'spread_amount'  => $fees['spread_amount'],
                    'crypto_asset'   => strtoupper($request->asset),
                    'crypto_network' => $request->network,
                    'crypto_amount'  => $cryptoAmt,
                    'rate'           => $fees['display_rate'],
                    'status'         => 'awaiting_crypto',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                    'rate_locked_at' => now(),
                    'rate_expires_at'=> now()->addSeconds(config('payyigi.rate_lock_seconds', 60)),
                    'metadata'       => [
                        'market_rate' => $fees['market_rate'],
                        'gross_ngn'   => $fees['gross_ngn'],
                        'total_fee'   => $fees['total_fee'],
                    ],
                ]);

                // Breet generates a unique deposit address for this order.
                // NGN payout goes to our company account — we credit user wallet on confirmation.
                $breetOrder = app(BreetService::class)->createSellOrder(
                    asset:          $request->asset,
                    network:        $request->network,
                    amount:         $cryptoAmt,
                    reference:      $txn->reference,
                    accountNumber:  config('payyigi.company_account_number'),
                    bankCode:       config('payyigi.company_bank_code'),
                    accountName:    config('payyigi.company_account_name'),
                );

                $txn->update([
                    'provider_order_id'  => $breetOrder['id']        ?? null,
                    'provider_reference' => $breetOrder['reference']  ?? null,
                    'deposit_address'    => $breetOrder['address']    ?? null,
                    'provider_response'  => $breetOrder,
                ]);

                return $txn;
            });

            // Poll Breet for status every 30s — credits wallet when completed
            PollSellStatus::dispatch($transaction)->delay(now()->addSeconds(30));

            AuditLog::record('transaction.sell_initiated', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'asset'      => $transaction->crypto_asset,
                    'network'    => $transaction->crypto_network,
                    'amount'     => $transaction->crypto_amount,
                    'ngn'        => $transaction->amount,
                    'reference'  => $transaction->reference,
                ],
            ]);

            return response()->json([
                'message' => 'Sell order created. Send your crypto to the address below. Your wallet will be credited once confirmed.',
                'data'    => [
                    'reference'       => $transaction->reference,
                    'asset'           => $transaction->crypto_asset,
                    'network'         => $transaction->crypto_network,
                    'amount_to_send'  => $transaction->crypto_amount,
                    'deposit_address' => $transaction->deposit_address,
                    'rate'            => $transaction->rate,
                    'gross_ngn'       => $fees['gross_ngn'],
                    'provider_fee'    => $fees['provider_fee'],
                    'platform_fee'    => $fees['platform_fee'],
                    'total_fee'       => $fees['total_fee'],
                    'ngn_to_receive'  => $transaction->amount,
                    'destination'     => 'wallet balance',
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