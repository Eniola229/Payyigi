<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\LocalRamp\LocalRampService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyController extends Controller
{
    public function __construct(private readonly LocalRampService $localRamp) {}

    /**
     * GET /api/v1/buy/rate?asset=USDT&ngn_amount=50000
     *
     * Formula from LocalRamp docs:
     * receiver_amount = ((sender_amount - processor_fee) / exchange_rate) - network_fee
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'      => 'required|string',
            'ngn_amount' => 'required|numeric|min:500',
        ]);

        try {
            $quote      = $this->localRamp->getBuyQuote($request->asset);
            $rate       = $quote['rate'];

            $exchangeRate  = (float) $rate['exchange_rate'];
            $processorFee  = (float) $rate['processor_fee']['fee']; // flat NGN
            $networkFee    = (float) $rate['network_fee'];           // in crypto
            $ngnAmount     = (float) $request->ngn_amount;

            // Apply our spread — user gets less crypto (we charge more per unit)
            $spreadPct     = config('payyigi.platform_fee_tiers.0.percent', 3.5);
            $adjustedRate  = round($exchangeRate * (1 + ($spreadPct / 100)), 2);

            // LocalRamp formula with our adjusted rate
            $cryptoOut = round((($ngnAmount - $processorFee) / $adjustedRate) - $networkFee, 8);

            return response()->json([
                'data' => [
                    'asset'          => strtoupper($request->asset),
                    'ngn_amount'     => $ngnAmount,
                    'exchange_rate'  => $adjustedRate,
                    'processor_fee'  => $processorFee,
                    'network_fee'    => $networkFee,
                    'crypto_amount'  => max(0, $cryptoOut),
                    'spread_percent' => $spreadPct,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
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