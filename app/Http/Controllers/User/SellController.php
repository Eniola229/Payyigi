<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
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
            $rateData   = $this->breet->getRate($request->asset);
            $marketRate = (float) $rateData['rate'];
            $fees       = $this->breet->calculateFees((float) $request->amount, $marketRate);

            return response()->json([
                'data' => [
                    'asset'          => strtoupper($request->asset),
                    'network'        => $request->network,
                    'crypto_amount'  => (float) $request->amount,
                    'rate'           => $fees['display_rate'],
                    'gross_ngn'      => $fees['gross_ngn'],
                    'platform_fee'   => $fees['platform_fee'],   // 0.5%
                    'breet_fee'      => $fees['breet_fee'],       // 0.5%
                    'total_fee'      => $fees['total_fee'],       // 1.0%
                    'ngn_amount'     => $fees['net_ngn'],         // what hits wallet
                    'spread_percent' => config('payyigi.spread_percent', 4),
                    'fee_percent'    => 1.0,
                    'rate_valid_for' => config('payyigi.rate_lock_seconds', 60) . ' seconds',
                    'destination'    => 'wallet balance',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/sell
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'   => 'required|string|in:BTC,USDT,SOL,ETH,BNB,TRX,XRP,LTC,BCH,USDC,AVAX,TON,DOGE',
            'network' => 'required|string',
            'amount'  => 'required|numeric|min:0.000001',
        ]);

        $user = $request->user();

        $defaultBank = $user->bankAccounts()->where('is_default', true)->first()
                    ?? $user->bankAccounts()->first();

        if (!$defaultBank) {
            return response()->json(['message' => 'Please add a bank account before selling.'], 422);
        }

        try {
            $rateData   = $this->breet->getRate($request->asset);
            $marketRate = (float) $rateData['rate'];
            $cryptoAmt  = (float) $request->amount;
            $fees       = $this->breet->calculateFees($cryptoAmt, $marketRate);

            $transaction = DB::transaction(function () use ($user, $defaultBank, $request, $cryptoAmt, $fees, $marketRate) {
                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'sell',
                    'entry_type'     => 'credit',
                    'currency'       => 'NGN',
                    'amount'         => $fees['net_ngn'],      // net after all fees — this hits wallet
                    'fee'            => $fees['platform_fee'], // PayYigi platform fee
                    'breet_fee'      => $fees['breet_fee'],    // Breet fee
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
                        'market_rate' => $marketRate,
                        'gross_ngn'   => $fees['gross_ngn'],
                        'total_fee'   => $fees['total_fee'],
                    ],
                ]);

                $breetOrder = app(BreetService::class)->createSellOrder(
                    asset:       $request->asset,
                    network:     $request->network,
                    amount:      $cryptoAmt,
                    bankAccount: [
                        'bank_code'      => $defaultBank->bank_code,
                        'account_number' => $defaultBank->account_number,
                        'account_name'   => $defaultBank->account_name,
                    ],
                    reference: $txn->reference,
                );

                $txn->update([
                    'breet_order_id'  => $breetOrder['id']        ?? null,
                    'breet_reference' => $breetOrder['reference'] ?? null,
                    'deposit_address' => $breetOrder['address']   ?? null,
                    'breet_response'  => $breetOrder,
                ]);

                return $txn;
            });

            AuditLog::record('transaction.sell_initiated', [
                'user_id'      => $user->id,
                'auditable_id' => $transaction->id,
            ]);

            return response()->json([
                'message' => 'Send your crypto to the address below. Your wallet will be credited once confirmed.',
                'data'    => [
                    'reference'       => $transaction->reference,
                    'asset'           => $transaction->crypto_asset,
                    'network'         => $transaction->crypto_network,
                    'amount_to_send'  => $transaction->crypto_amount,
                    'deposit_address' => $transaction->deposit_address,
                    'rate'            => $transaction->rate,
                    'gross_ngn'       => $fees['gross_ngn'],
                    'platform_fee'    => $fees['platform_fee'],
                    'breet_fee'       => $fees['breet_fee'],
                    'total_fee'       => $fees['total_fee'],
                    'ngn_to_receive'  => $transaction->amount, // net — what hits wallet
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
                ->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $txn = $request->user()->transactions()
            ->where('reference', $reference)->where('type', 'sell')->firstOrFail();
        return response()->json(['data' => $txn]);
    }
}
