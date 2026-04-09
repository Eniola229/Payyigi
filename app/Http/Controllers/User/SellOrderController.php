<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Breet\BreetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SellOrderController extends Controller
{
    // Spread percentage PayYigi takes (4% = 0.04)
    private const SPREAD_PERCENT = 0.04;

    // Rate lock window in seconds (60 seconds)
    private const RATE_LOCK_SECONDS = 60;

    // Supported assets and their networks
    private const SUPPORTED_ASSETS = [
        'BTC'  => ['bitcoin'],
        'USDT' => ['trc20', 'erc20', 'bep20'],
        'SOL'  => ['solana'],
        'ETH'  => ['erc20'],
        'BNB'  => ['bep20'],
        'USDC' => ['erc20', 'trc20', 'bep20'],
    ];

    public function __construct(private readonly BreetService $breet) {}

    /**
     * STEP 1 — Get rate for a specific asset.
     * Returns market rate, our displayed rate (after spread), and a preview of what the user gets.
     *
     * GET /sell/rate?asset=USDT&amount=100&network=trc20
     */
    public function getRate(Request $request): JsonResponse
    {
        $request->validate([
            'asset'   => 'required|string|in:' . implode(',', array_keys(self::SUPPORTED_ASSETS)),
            'amount'  => 'required|numeric|min:0.00000001',
            'network' => 'required|string',
        ]);

        $asset   = strtoupper($request->asset);
        $network = strtolower($request->network);
        $amount  = (float) $request->amount;

        // Validate network for asset
        if (!in_array($network, self::SUPPORTED_ASSETS[$asset] ?? [])) {
            return response()->json([
                'message' => "Network '{$network}' is not supported for {$asset}.",
                'supported_networks' => self::SUPPORTED_ASSETS[$asset],
            ], 422);
        }

        try {
            $rateData = $this->breet->getRate($asset);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unable to fetch rates. Please try again.'], 503);
        }

        $marketRate    = $rateData['market_rate'];
        $displayedRate = $marketRate * (1 - self::SPREAD_PERCENT); // user sees less
        $spreadAmount  = ($marketRate - $displayedRate) * $amount;
        $youReceive    = $displayedRate * $amount;
        $breetFee      = $youReceive * 0.005; // Breet's 0.5%
        $netReceive    = $youReceive - $breetFee;

        return response()->json([
            'data' => [
                'asset'          => $asset,
                'network'        => $network,
                'amount'         => $amount,
                'market_rate'    => $marketRate,
                'displayed_rate' => round($displayedRate, 2),
                'you_receive'    => round($netReceive, 2),
                'currency'       => 'NGN',
                'spread_percent' => (self::SPREAD_PERCENT * 100) . '%',
                'rate_valid_for' => self::RATE_LOCK_SECONDS . ' seconds',
                'fetched_at'     => $rateData['fetched_at'],
            ],
        ]);
    }

    /**
     * STEP 2 — Create a sell order.
     * Locks rate for 60 seconds, generates crypto deposit address via Breet.
     *
     * POST /sell/orders
     * body: { asset, network, amount, bank_account_id, transaction_pin }
     *
     * transaction_pin is consumed by the RequireTransactionPin middleware.
     */
    public function createOrder(Request $request): JsonResponse
    {
        $request->validate([
            'asset'           => 'required|string|in:' . implode(',', array_keys(self::SUPPORTED_ASSETS)),
            'network'         => 'required|string',
            'amount'          => 'required|numeric|min:0.00000001',
            'bank_account_id' => 'required|uuid|exists:bank_accounts,id',
        ]);

        $user      = $request->user();
        $asset     = strtoupper($request->asset);
        $network   = strtolower($request->network);
        $amount    = (float) $request->amount;

        // Validate network
        if (!in_array($network, self::SUPPORTED_ASSETS[$asset] ?? [])) {
            return response()->json(['message' => "Network '{$network}' is not supported for {$asset}."], 422);
        }

        // Confirm bank account belongs to user
        $bankAccount = BankAccount::where('id', $request->bank_account_id)
                                  ->where('user_id', $user->id)
                                  ->first();

        if (!$bankAccount) {
            return response()->json(['message' => 'Bank account not found.'], 404);
        }

        // Fetch live rate
        try {
            $rateData = $this->breet->getRate($asset);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unable to fetch current rates. Please try again.'], 503);
        }

        $marketRate    = $rateData['market_rate'];
        $displayedRate = $marketRate * (1 - self::SPREAD_PERCENT);
        $ngnAmount     = round($displayedRate * $amount, 2);
        $breetFee      = round($ngnAmount * 0.005, 2);
        $netAmount     = round($ngnAmount - $breetFee, 2);
        $spreadAmount  = round(($marketRate - $displayedRate) * $amount, 2);

        return DB::transaction(function () use (
            $user, $asset, $network, $amount, $bankAccount,
            $marketRate, $displayedRate, $ngnAmount, $breetFee, $netAmount, $spreadAmount
        ) {
            // Create the transaction record first (pending)
            $transaction = Transaction::create([
                'user_id'         => $user->id,
                'wallet_id'       => $user->wallet->id,
                'type'            => 'sell',
                'entry_type'      => 'credit',
                'currency'        => 'NGN',
                'amount'          => $ngnAmount,
                'fee'             => 0,
                'breet_fee'       => $breetFee,
                'net_amount'      => $netAmount,
                'spread_amount'   => $spreadAmount,
                'balance_before'  => $user->wallet->balance,
                'balance_after'   => $user->wallet->balance, // will update when completed
                'crypto_asset'    => $asset,
                'crypto_network'  => $network,
                'crypto_amount'   => request()->amount,
                'rate'            => $displayedRate,
                'bank_account_id' => $bankAccount->id,
                'bank_name'       => $bankAccount->bank_name,
                'bank_code'       => $bankAccount->bank_code,
                'account_number'  => $bankAccount->account_number,
                'account_name'    => $bankAccount->account_name,
                'status'          => 'pending',
                'session_id'      => session()->getId(),
                'ip_address'      => request()->ip(),
                'user_agent'      => request()->userAgent(),
                'rate_locked_at'  => now(),
                'rate_expires_at' => now()->addSeconds(self::RATE_LOCK_SECONDS),
            ]);

            // Create order on Breet — get deposit address
            try {
                $breetOrder = $this->breet->createSellOrder(
                    asset:       $asset,
                    network:     $network,
                    amount:      request()->amount,
                    reference:   $transaction->reference,
                    bankAccount: [
                        'account_number' => $bankAccount->account_number,
                        'bank_code'      => $bankAccount->bank_code,
                        'account_name'   => $bankAccount->account_name,
                    ],
                );
            } catch (\Exception $e) {
                // Breet call failed — fail the transaction and return error
                $transaction->update(['status' => 'failed', 'failure_reason' => $e->getMessage(), 'failed_at' => now()]);
                Log::error('Breet createSellOrder failed', ['transaction' => $transaction->reference, 'error' => $e->getMessage()]);
                throw new \Exception('Failed to create order. Please try again.');
            }

            // Store Breet response on transaction
            $transaction->update([
                'status'          => 'awaiting_crypto',
                'breet_order_id'  => $breetOrder['id']  ?? $breetOrder['order_id'] ?? null,
                'breet_reference' => $breetOrder['reference'] ?? null,
                'deposit_address' => $breetOrder['address'] ?? $breetOrder['deposit_address'] ?? null,
                'breet_response'  => $breetOrder,
            ]);

            AuditLog::record('transaction.sell_order_created', [
                'user_id'        => $user->id,
                'auditable_type' => Transaction::class,
                'auditable_id'   => $transaction->id,
                'new_values'     => [
                    'reference' => $transaction->reference,
                    'asset'     => $asset,
                    'amount'    => request()->amount,
                    'ngn'       => $netAmount,
                ],
            ]);

            return response()->json([
                'message' => 'Order created. Please send crypto to the address below within 60 seconds.',
                'data'    => [
                    'transaction_id'  => $transaction->id,
                    'reference'       => $transaction->reference,
                    'status'          => $transaction->status,
                    'crypto'          => [
                        'asset'           => $asset,
                        'network'         => $network,
                        'amount'          => $transaction->crypto_amount,
                        'deposit_address' => $transaction->deposit_address,
                    ],
                    'payout'          => [
                        'currency'      => 'NGN',
                        'you_receive'   => $netAmount,
                        'rate_used'     => $displayedRate,
                    ],
                    'bank'            => [
                        'bank_name'      => $bankAccount->bank_name,
                        'account_number' => $bankAccount->account_number,
                        'account_name'   => $bankAccount->account_name,
                    ],
                    'rate_expires_at' => $transaction->rate_expires_at->toISOString(),
                ],
            ], 201);
        });
    }

    /**
     * Get all sell orders for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->transactions()
            ->where('type', 'sell')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(15);

        return response()->json(['data' => $orders]);
    }

    /**
     * Get a specific sell order.
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, 403);
        abort_if($transaction->type !== 'sell', 404);

        return response()->json(['data' => $transaction->load('bankAccount')]);
    }

    /**
     * Supported assets and networks.
     */
    public function supportedAssets(): JsonResponse
    {
        return response()->json(['data' => self::SUPPORTED_ASSETS]);
    }
}
