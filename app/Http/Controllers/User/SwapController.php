<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\LocalRamp\LocalRampService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SwapController extends Controller
{
    public function __construct(private readonly LocalRampService $localRamp) {}

    /**
     * GET /api/v1/swap/rate?from=BTC&to=USDT&amount=0.01
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'from'   => 'required|string|different:to',
            'to'     => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
        ]);

        try {
            $rateData  = $this->localRamp->getSwapRate($request->from, $request->to);
            $rate      = (float) $rateData['rate']['amount'];
            $toAmount  = round((float) $request->amount * $rate, 8);

            return response()->json([
                'data' => [
                    'from'        => strtoupper($request->from),
                    'to'          => strtoupper($request->to),
                    'from_amount' => (float) $request->amount,
                    'to_amount'   => $toAmount,
                    'rate'        => $rate,
                    'note'        => 'Swap rates are not locked. Final amount may vary slightly.',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    /**
     * POST /api/v1/swap
     * Swaps between currencies in YOUR LocalRamp wallet.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'from'   => 'required|string|different:to',
            'to'     => 'required|string',
            'amount' => 'required|numeric|min:0.000001',
        ]);

        $user = $request->user();

        try {
            $rateData  = $this->localRamp->getSwapRate($request->from, $request->to);
            $rate      = (float) $rateData['rate']['amount'];
            $fromAmt   = (float) $request->amount;
            $toAmt     = round($fromAmt * $rate, 8);

            $transaction = DB::transaction(function () use ($user, $request, $fromAmt, $toAmt, $rate) {
                $txn = Transaction::create([
                    'user_id'        => $user->id,
                    'wallet_id'      => $user->wallet->id,
                    'type'           => 'swap',
                    'entry_type'     => 'debit',
                    'currency'       => 'NGN',
                    'amount'         => 0, // swap is crypto-to-crypto, no NGN
                    'net_amount'     => 0,
                    'crypto_asset'   => strtoupper($request->from),
                    'crypto_amount'  => $fromAmt,
                    'swap_to_asset'  => strtoupper($request->to),
                    'swap_to_amount' => $toAmt,
                    'rate'           => $rate,
                    'status'         => 'processing',
                    'session_id'     => session()->getId(),
                    'ip_address'     => request()->ip(),
                    'user_agent'     => request()->userAgent(),
                ]);

                // Call LocalRamp to initiate swap
                $swapData = app(LocalRampService::class)->initiateSwap(
                    fromCurrency: $request->from,
                    toCurrency:   $request->to,
                    fromAmount:   $fromAmt,
                );

                $txn->update([
                    'breet_order_id' => $swapData['reference'] ?? null,
                    'breet_response' => $swapData,
                ]);

                return $txn;
            });

            AuditLog::record('transaction.swap_initiated', [
                'user_id'      => $user->id,
                'auditable_id' => $transaction->id,
            ]);

            return response()->json([
                'message' => 'Swap initiated successfully.',
                'data'    => [
                    'reference'  => $transaction->reference,
                    'from'       => $transaction->crypto_asset,
                    'from_amount'=> $transaction->crypto_amount,
                    'to'         => $transaction->swap_to_asset,
                    'to_amount'  => $transaction->swap_to_amount,
                    'rate'       => $transaction->rate,
                    'status'     => $transaction->status,
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