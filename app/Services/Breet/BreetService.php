<?php

namespace App\Services\Breet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BreetService
{
    private string $baseUrl;
    private string $appId;
    private string $appSecret;
    private string $env;

    public function __construct()
    {
        $this->baseUrl   = config('services.breet.base_url', 'https://api.breet.io/v1');
        $this->appId     = config('services.breet.app_id')     ?? throw new \RuntimeException('BREET_APP_ID is not set in your .env file.');
        $this->appSecret = config('services.breet.app_secret') ?? throw new \RuntimeException('BREET_APP_SECRET is not set in your .env file.');
        $this->env       = config('services.breet.env', 'production');
    }

    private function http()
    {
        return Http::withHeaders([
            'x-app-id'     => $this->appId,
            'x-app-secret' => $this->appSecret,
            'X-Breet-Env'  => $this->env,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(30);
    }

    // ── ASSETS ────────────────────────────────────────────────────────────────

    /**
     * Fetch all supported deposit assets.
     * Endpoint: GET /trades/assets
     * Used by SyncBreetAssets command to populate the breet_assets table.
     */
    public function getDepositAssets(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/trades/assets");

        if ($response->failed()) {
            Log::error('Breet getDepositAssets failed', ['response' => $response->json()]);
            throw new \Exception('Unable to fetch deposit assets from Breet.');
        }

        return $response->json('data');
    }

    // ── RATES & PRICES ────────────────────────────────────────────────────────

    /**
     * Get current USD market price for 1 unit of a crypto asset.
     * Endpoint: GET /trades/pbc/sell/assets/market/converter?from=BTC&to=usd
     *
     * Use this to convert a user's crypto amount → USD before calling getRateCalculator.
     * These are global market prices, not Breet-specific rates.
     *
     * Response: { data: { quote: { USD: { price: 105000.00 } } } }
     */
    public function getCryptoUsdPrice(string $symbol): float
    {
        $response = $this->http()->get("{$this->baseUrl}/trades/pbc/sell/assets/market/converter", [
            'from' => strtolower($symbol),
            'to'   => 'usd',
        ]);

        if ($response->failed()) {
            Log::error('Breet getCryptoUsdPrice failed', [
                'symbol' => $symbol,
                'body'   => $response->json(),
            ]);
            throw new \Exception("Unable to fetch USD price for {$symbol}.");
        }

        $price = $response->json('data.quote.USD.price') ?? null;

        if (!$price) {
            throw new \Exception("USD price not available for {$symbol}.");
        }

        return (float) $price;
    }

    /**
     * Get NGN/GHS rate for a given asset + USD amount.
     * Endpoint: POST /trades/pbc/sell/rate-calculator/{assetId}
     *
     * IMPORTANT: amountInUSD must be the USD value, NOT the crypto amount.
     * Use getCryptoUsdPrice() first to convert: cryptoAmount * usdPrice = amountInUSD
     *
     * Your markup % (set on Breet dashboard) is already factored into the rate.
     * Do NOT add any fee on top — Breet handles everything.
     *
     * Response: { NGNAmount, GHSAmount, rate, cryptoAmount }
     */
    public function getRateCalculator(string $assetId, float $amountInUSD, string $currency = 'ngn'): array
    {
        $response = $this->http()->post(
            "{$this->baseUrl}/trades/pbc/sell/rate-calculator/{$assetId}",
            [
                'amountInUSD' => $amountInUSD,
                'currency'    => strtolower($currency),
            ]
        );

        if ($response->failed()) {
            Log::error('Breet getRateCalculator failed', [
                'assetId' => $assetId,
                'usd'     => $amountInUSD,
                'body'    => $response->json(),
            ]);
            throw new \Exception('Unable to fetch rate. Please try again.');
        }

        return $response->json('data');
    }

    // ── WALLET ADDRESS (DEPOSIT / OFF-RAMP) ───────────────────────────────────

    /**
     * Generate a permanent deposit address for a user + asset.
     * Endpoint: POST /trades/sell/assets/{assetId}/generate-address
     *
     * Addresses are PERMANENT and REUSABLE — generate once per user per asset.
     * Pass bankId + accountNumber to enable auto-settlement to user's bank.
     *
     * Response: { id (walletId), address }
     */
    public function generateDepositAddress(
        string  $assetId,
        string  $label,
        ?string $bankId        = null,
        ?string $accountNumber = null,
        ?string $narration     = null,
    ): array {
        $payload = ['label' => $label];

        if ($bankId && $accountNumber) {
            $payload['bankId']        = $bankId;
            $payload['accountNumber'] = $accountNumber;
            $payload['narration']     = $narration ?? 'PayYigi sell order';
        }

        $response = $this->http()->post(
            "{$this->baseUrl}/trades/sell/assets/{$assetId}/generate-address",
            $payload
        );

        Log::info('Breet generateDepositAddress', [
            'assetId' => $assetId,
            'label'   => $label,
            'status'  => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to generate deposit address.';
            Log::error('Breet generateDepositAddress failed', [
                'assetId' => $assetId,
                'error'   => $error,
            ]);
            throw new \Exception($error);
        }

        return $response->json('data');
    }

    /**
     * Update the linked bank on an existing wallet address.
     * Endpoint: PUT /trades/wallets/{walletId}/bank
     */
    public function updateWalletBank(string $walletId, string $bankId, string $accountNumber): array
    {
        $response = $this->http()->put(
            "{$this->baseUrl}/trades/wallets/{$walletId}/bank",
            [
                'bankId'        => $bankId,
                'accountNumber' => $accountNumber,
            ]
        );

        if ($response->failed()) {
            throw new \Exception($response->json('message') ?? 'Failed to update wallet bank.');
        }

        return $response->json('data');
    }

    /**
     * Enable or disable auto-settlement on a wallet address.
     * Endpoint: PUT /trades/wallets/{walletId}/auto-settlement
     */
    public function setAutoSettlement(string $walletId, bool $enabled): array
    {
        $response = $this->http()->put(
            "{$this->baseUrl}/trades/wallets/{$walletId}/auto-settlement",
            ['autoSettlement' => $enabled]
        );

        if ($response->failed()) {
            throw new \Exception($response->json('message') ?? 'Failed to update auto-settlement.');
        }

        return $response->json('data');
    }

    // ── TRANSACTIONS ──────────────────────────────────────────────────────────

    /**
     * Fetch a Breet wallet by its ID.
     * Endpoint: GET /trades/wallets/{id}
     * Used by PollSellStatus to confirm wallet exists and is active.
     */
    public function getWallet(string $walletId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/trades/wallets/{$walletId}");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch wallet: ' . ($response->json('message') ?? 'unknown error'));
        }

        return $response->json('data');
    }

    /**
     * Fetch a Breet transaction by its Breet transaction ID.
     * Endpoint: GET /trades/transactions/{id}
     *
     * Pass $txn->provider_order_id (the wallet id returned by generateDepositAddress).
     *
     * Key response fields:
     *   status         — pending | completed | flagged
     *   amount         — NGN amount (gross, after Breet fee)
     *   feeAmount      — Breet's platform fee (already deducted)
     *   rate           — NGN per USD at trade time
     *   cryptoReceived — actual crypto received on-chain
     *   txHash         — blockchain tx hash
     */
    public function getTransaction(string $breetTransactionId): array
    {
        $response = $this->http()->get(
            "{$this->baseUrl}/trades/transactions/{$breetTransactionId}"
        );

        if ($response->failed()) {
            throw new \Exception('Unable to fetch transaction status.');
        }

        return $response->json('data');
    }

    // ── BANKS ─────────────────────────────────────────────────────────────────

    /**
     * Fetch Breet's bank list.
     * Endpoint: GET /payments/banks
     * Use this to get bankId values needed for generateDepositAddress.
     */
    public function getBanks(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/payments/banks");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch bank list.');
        }

        return $response->json('data');
    }

    // ── WEBHOOK ───────────────────────────────────────────────────────────────

    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        $secret   = config('services.breet.webhook_secret');
        $expected = hash_hmac('sha256', $rawPayload, $secret);
        return hash_equals($expected, $signature);
    }

    // ── SWAP ──────────────────────────────────────────────────────────────────

    public function getSwapRate(string $fromAsset, string $toAsset): array
    {
        $response = $this->http()->get("{$this->baseUrl}/swap/rates", [
            'from' => strtolower($fromAsset),
            'to'   => strtolower($toAsset),
        ]);

        if ($response->failed()) {
            throw new \Exception("Unable to fetch swap rate for {$fromAsset} → {$toAsset}.");
        }

        return $response->json('data');
    }

    public function createSwapOrder(
        string $fromAsset,
        string $fromNetwork,
        string $toAsset,
        float  $amount,
        string $reference,
        string $destinationAddress,
    ): array {
        $payload = [
            'from_asset'          => strtolower($fromAsset),
            'from_network'        => strtolower($fromNetwork),
            'to_asset'            => strtolower($toAsset),
            'amount'              => $amount,
            'reference'           => $reference,
            'destination_address' => $destinationAddress,
        ];

        $response = $this->http()->post("{$this->baseUrl}/swap", $payload);

        if ($response->failed()) {
            throw new \Exception($response->json('message') ?? 'Failed to create swap order.');
        }

        return $response->json('data');
    }
}