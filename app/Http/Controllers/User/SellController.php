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
use Illuminate\Support\Facades\Log;
use App\Services\Korapay\KorapayService;

class SellController extends Controller
{
    public function __construct(
        private readonly BreetService $breet,
        private readonly KorapayService $korapay,
    ) {}

    /**
     * GET /api/v1/sell/assets
     */
    public function assets(): JsonResponse
    {
        $assets = BreetAsset::where('is_active', true)
            ->orderBy('symbol')
            ->get(['id', 'symbol', 'name', 'network', 'icon', 'minimum']);

        return response()->json(['data' => $assets]);
    }

    /**
     * GET /api/v1/sell/rate?asset=BTC&network=Bitcoin&amount=21.00&currency=ngn
     * `amount` is USD value.
     */
public function getRate(Request $request): JsonResponse
{
    $request->validate([
        'asset'    => 'required|string',
        'network'  => 'required|string',
        'amount'   => 'required|numeric|min:0.01',
        'currency' => 'sometimes|string|in:ngn,ghs',
    ]);

    try {
        $breetAsset  = BreetAsset::resolve($request->asset, $request->network);
        $amountInUSD = (float) $request->amount;

        // Check against asset minimum (stored in USD on breet_assets table)
        if ($amountInUSD < (float) $breetAsset->minimum) {
            return response()->json([
                'message' => "Minimum amount for {$breetAsset->name} is \${$breetAsset->minimum} USD.",
                'errors'  => [
                    'amount' => ["Amount must be at least \${$breetAsset->minimum} USD for {$breetAsset->name}."],
                ],
            ], 422);
        }

        $rateData = $this->breet->getRateCalculator(
            $breetAsset->id,
            $amountInUSD,
            $request->currency ?? 'ngn'
        );

        return response()->json([
            'data' => [
                'asset'         => strtoupper($request->asset),
                'network'       => $request->network,
                'usd_value'     => $amountInUSD,
                'currency'      => strtoupper($request->currency ?? 'NGN'),
                'rate'          => $rateData['rate'],
                'ngn_amount'    => $rateData['NGNAmount'],
                'crypto_amount' => $rateData['cryptoAmount'],
                'minimum'       => $breetAsset->minimum,
                'note'          => 'Final amount is determined by Breet at settlement time.',
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
            'asset'           => 'required|string',
            'network'         => 'required|string',
            'amount'          => 'required|numeric|min:0.000001',
            'currency'        => 'sometimes|string|in:ngn,ghs',
            'bank_account_id' => 'required|uuid|exists:bank_accounts,id',
        ]);

        $user        = $request->user();
        $bankAccount = $user->bankAccounts()->findOrFail($request->bank_account_id);

        try {
            $breetAsset   = BreetAsset::resolve($request->asset, $request->network);
            $cryptoAmount = (float) $request->amount;

            $usdPrice    = $this->breet->getCryptoUsdPrice($breetAsset->symbol);
            $amountInUSD = $cryptoAmount * $usdPrice;

            $rateData = $this->breet->getRateCalculator(
                $breetAsset->id,
                $amountInUSD,
                $request->currency ?? 'ngn'
            );

            $transaction = DB::transaction(function () use (
                $user, $bankAccount, $request, $breetAsset, $cryptoAmount, $amountInUSD, $rateData
            ) {
                $txn = Transaction::create([
                    'user_id'         => $user->id,
                    'wallet_id'       => $user->wallet->id,
                    'type'            => 'sell',
                    'entry_type'      => 'debit',
                    'currency'        => strtoupper($request->currency ?? 'NGN'),
                    'amount'          => 0,
                    'net_amount'      => 0,
                    'crypto_asset'    => strtoupper($request->asset),
                    'crypto_network'  => $request->network,
                    'crypto_amount'   => $cryptoAmount,
                    'rate'            => $rateData['rate'] ?? 0,
                    'bank_account_id' => $bankAccount->id,
                    'bank_name'       => $bankAccount->bank_name,
                    'bank_code'       => $bankAccount->bank_code,
                    'account_number'  => $bankAccount->account_number,
                    'account_name'    => $bankAccount->account_name,
                    'status'          => 'awaiting_crypto',
                    'session_id'      => session()->getId(),
                    'ip_address'      => request()->ip(),
                    'user_agent'      => request()->userAgent(),
                    'rate_locked_at'  => now(),
                    'rate_expires_at' => now()->addSeconds((int) config('payyigi.rate_lock_seconds', 60)),
                    'metadata'        => [
                        'estimated_ngn'  => $rateData['NGNAmount']    ?? 0,
                        'usd_value'      => round($amountInUSD, 2),
                        'breet_asset_id' => $breetAsset->id,
                    ],
                ]);

                // ── WALLET REUSE ──────────────────────────────────────────────
                // Breet addresses are permanent and reusable per user per asset.
                // If this user already has a generated wallet for this asset,
                // reuse it. Match on breet_wallet_id (not null = successfully stored).
                $existingWalletTxn = Transaction::where('user_id', $user->id)
                    ->where('type', 'sell')
                    ->where('crypto_asset', strtoupper($request->asset))
                    ->where('crypto_network', $request->network)
                    ->whereNotNull('breet_wallet_id')
                    ->whereNotNull('breet_vault_id')
                    ->whereNotNull('deposit_address')
                    ->where('id', '!=', $txn->id)
                    ->latest()
                    ->first();

                if ($existingWalletTxn) {
                    // Reuse — carry over all three Breet identifiers
                    $txn->update([
                        'breet_wallet_id' => $existingWalletTxn->breet_wallet_id,
                        'breet_vault_id'  => $existingWalletTxn->breet_vault_id,
                        'deposit_address' => $existingWalletTxn->deposit_address,
                    ]);

                    Log::info('Breet wallet reused for sell order', [
                        'reference'       => $txn->reference,
                        'breet_wallet_id' => $existingWalletTxn->breet_wallet_id,
                        'breet_vault_id'  => $existingWalletTxn->breet_vault_id,
                        'deposit_address' => $existingWalletTxn->deposit_address,
                    ]);
                } else {
                    // ── GENERATE NEW WALLET ───────────────────────────────────
                    // generateDepositAddress() now returns unwrapped data:
                    //   ['wallet_id' => '...', 'vault_id' => '...', 'address' => '...', 'raw' => [...]]
                    $breetWallet = app(BreetService::class)->generateDepositAddress(
                        assetId:        $breetAsset->id,
                        label:          "user-{$user->id}-{$breetAsset->symbol}",
                        bankId:         $bankAccount->bank_code,
                        accountNumber:  $bankAccount->account_number,
                        narration:      'PayYigi sell order',
                        autoSettlement: true,
                    );

                    // generateDepositAddress() throws if data is missing,
                    // but double-check here before writing to DB.
                    if (empty($breetWallet['wallet_id']) || empty($breetWallet['address'])) {
                        throw new \RuntimeException(
                            'Breet wallet generation returned unusable data: ' . json_encode($breetWallet)
                        );
                    }

                    $txn->update([
                        'breet_wallet_id'  => $breetWallet['wallet_id'], // MongoDB ObjectId
                        'breet_vault_id'   => $breetWallet['vault_id'],  // numeric string for webhook matching
                        'deposit_address'  => $breetWallet['address'],
                        'provider_response'=> $breetWallet['raw'],
                    ]);

                    Log::info('Breet wallet generated for sell order', [
                        'reference'       => $txn->reference,
                        'breet_wallet_id' => $breetWallet['wallet_id'],
                        'breet_vault_id'  => $breetWallet['vault_id'],
                        'deposit_address' => $breetWallet['address'],
                    ]);
                }

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
                'message' => 'Sell order created. Send your crypto to the address below.',
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
                    'status'     => $transaction->status,
                    'expires_at' => $transaction->rate_expires_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('SellController::initiate failed', [
                'user_id' => $user->id ?? null,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
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

    public function banks(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->korapay->getBanks()]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}