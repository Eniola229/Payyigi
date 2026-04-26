<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\LocalRamp\LocalRampService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellController extends Controller
{
    public function __construct(private readonly LocalRampService $localRamp) {}

    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'  => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
        ]);

        try {
            $rateData = $this->localRamp->getSellRate($request->asset);
            $limits   = $this->localRamp->getSellLimits($request->asset);
            $fees     = $this->localRamp->calculateFees((float) $request->amount, $rateData);

            return response()->json([
                'data' => [
                    'asset'          => strtoupper($request->asset),
                    'crypto_amount'  => (float) $request->amount,
                    'rate'           => $fees['display_rate'],
                    'gross_ngn'      => $fees['gross_ngn'],
                    'localramp_fee'  => $fees['localramp_fee'],
                    'platform_fee'   => $fees['platform_fee'],
                    'total_fee'      => $fees['total_fee'],
                    'ngn_amount'     => $fees['net_ngn'],
                    'spread_percent' => $fees['spread_percent'],
                    'min_amount'     => $limits['from_minimum'] ?? null,
                    'max_amount'     => $limits['from_maximum'] ?? null,
                    'destination'    => 'wallet balance',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'  => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
        ]);

        $user = $request->user();

        try {
            $rateData  = $this->localRamp->getSellRate($request->asset);
            $cryptoAmt = (float) $request->amount;
            $fees      = $this->localRamp->calculateFees($cryptoAmt, $rateData);

            $transaction = DB::transaction(function () use (
                $user, $request, $cryptoAmt, $fees
            ) {
                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'sell',
                    'entry_type'     => 'credit',
                    'currency'       => 'NGN',
                    'amount'         => $fees['net_ngn'],
                    'fee'            => $fees['platform_fee'],
                    'provider_fee'      => $fees['localramp_fee'],
                    'net_amount'     => $fees['net_ngn'],
                    'spread_amount'  => $fees['spread_amount'],
                    'crypto_asset'   => strtoupper($request->asset),
                    'crypto_amount'  => $cryptoAmt,
                    'rate'           => $fees['display_rate'],
                    // No user bank account here — NGN goes to COMPANY account
                    'status'         => 'processing',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                    'metadata'       => [
                        'market_rate'   => $fees['market_rate'],
                        'gross_ngn'     => $fees['gross_ngn'],
                        'localramp_fee' => $fees['localramp_fee'],
                    ],
                ]);

                // LocalRamp pays NGN to YOUR COMPANY account
                // User's wallet gets credited when polling confirms completion
                $localRampData = app(LocalRampService::class)->initiateSell(
                    reference:     $txn->reference,
                    email:         $user->email,
                    fromCurrency:  $request->asset,
                    fromAmount:    $cryptoAmt,
                    accountNumber: config('payyigi.company_account_number'),
                    bankCode:      config('payyigi.company_bank_code'),
                );

                $txn->update([
                    'provider_order_id'  => $localRampData['reference']        ?? null,
                    'provider_reference' => $localRampData['tx_ext_reference']  ?? null,
                    'provider_response'  => $localRampData,
                ]);

                return $txn;
            });

            // Poll LocalRamp for completion — when done, wallet is credited
            \App\Jobs\PollSellStatus::dispatch($transaction)->delay(now()->addSeconds(30));

            AuditLog::record('transaction.sell_initiated', [
                'user_id'      => $user->id,
                'auditable_id' => $transaction->id,
            ]);

            return response()->json([
                'message' => 'Sell initiated. Your wallet will be credited once confirmed.',
                'data'    => [
                    'reference'      => $transaction->reference,
                    'asset'          => $transaction->crypto_asset,
                    'crypto_amount'  => $transaction->crypto_amount,
                    'rate'           => $transaction->rate,
                    'gross_ngn'      => $fees['gross_ngn'],
                    'total_fee'      => $fees['total_fee'],
                    'ngn_to_receive' => $transaction->amount,
                    'destination'    => 'wallet balance',
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