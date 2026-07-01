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
        $this->appId     = config('services.breet.app_id')     ?? throw new \RuntimeException('BREET_APP_ID is not set.');
        $this->appSecret = config('services.breet.app_secret') ?? throw new \RuntimeException('BREET_APP_SECRET is not set.');
        $this->env       = config('services.breet.env', 'production');
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
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
     * GET /trades/assets
     * Fetch all supported deposit/sell assets.
     * Used by SyncBreetAssets command.
     */
    public function getDepositAssets(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/trades/assets");

        if ($response->failed()) {
            Log::error('Breet getDepositAssets failed', ['body' => $response->json()]);
            throw new \Exception('Unable to fetch deposit assets from Breet.');
        }

        return $response->json('data') ?? [];
    }

    // ── RATES & PRICES ────────────────────────────────────────────────────────

    /**
     * GET /trades/pbc/sell/assets/market/converter?from=BTC&to=usd
     *
     * Returns current USD market price for 1 unit of a crypto asset.
     * Use this to convert cryptoAmount → USD before calling getRateCalculator().
     *
     * Response shape: { data: { quote: { USD: { price: 105000.00 } } } }
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

        $price = $response->json('data.quote.USD.price');

        if (!$price) {
            throw new \Exception("USD price not available for {$symbol}.");
        }

        return (float) $price;
    }

    /**
     * POST /trades/pbc/sell/rate-calculator/{assetId}
     *
     * Returns NGN/GHS rate for a given asset + USD value.
     * amountInUSD = cryptoAmount * usdPrice (not raw crypto units).
     *
     * Response shape: { data: { NGNAmount, GHSAmount, rate, cryptoAmount } }
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

        return $response->json('data') ?? [];
    }

    // ── WALLET ADDRESS (DEPOSIT) ───────────────────────────────────────────────

    /**
     * POST /trades/sell/assets/{assetId}/generate-address
     *
     * Generates a PERMANENT, REUSABLE deposit address for one asset.
     * Call once per user per asset — store and reuse the result.
     *
     * Pass bankId + accountNumber + autoSettlement=true to have Breet
     * auto-settle incoming crypto directly to the user's bank account.
     *
     * ┌─────────────────────────────────────────────────────────────────┐
     * │  CRITICAL: Breet wraps ALL responses in a `data` key.           │
     * │  Response: { success, data: { id, address, vaultId, ... } }     │
     * │                                                                 │
     * │  `data.id`      = MongoDB wallet ObjectId  ← store as          │
     * │                   breet_wallet_id on the transaction.           │
     * │                   Used for GET /trades/wallets/{id}             │
     * │                                                                 │
     * │  `data.vaultId` = numeric vault ID (string) ← store as         │
     * │                   breet_vault_id on the transaction.            │
     * │                   This is what Breet sends in webhook           │
     * │                   payloads as `vaultId` — used for matching.   │
     * │                                                                 │
     * │  `data.address` = deposit address to show the user.            │
     * └─────────────────────────────────────────────────────────────────┘
     *
     * Returns: ['wallet_id' => '...', 'vault_id' => '...', 'address' => '...', 'raw' => [...]]
     */
    public function generateDepositAddress(
        string  $assetId,
        string  $label,
        ?string $bankId        = null,
        ?string $accountNumber = null,
        ?string $narration     = null,
        bool    $autoSettlement = true,
    ): array {
        $payload = ['label' => $label];

        if ($bankId && $accountNumber) {
            $payload['bankId']          = $bankId;
            $payload['accountNumber']   = $accountNumber;
            $payload['narration']       = substr($narration ?? 'PayYigi sell order', 0, 32);
            $payload['autoSettlement']  = $autoSettlement;
        }

        $response = $this->http()->post(
            "{$this->baseUrl}/trades/sell/assets/{$assetId}/generate-address",
            $payload
        );

        $body = $response->json();

        Log::info('Breet generateDepositAddress response', [
            'assetId' => $assetId,
            'label'   => $label,
            'status'  => $response->status(),
            'body'    => $body,
        ]);

        if ($response->failed() || empty($body['success'])) {
            $error = $body['message'] ?? 'Failed to generate deposit address.';
            Log::error('Breet generateDepositAddress failed', [
                'assetId' => $assetId,
                'error'   => $error,
                'body'    => $body,
            ]);
            throw new \Exception($error);
        }

        // Unwrap the `data` key — this is where the actual wallet info lives.
        $data = $body['data'] ?? [];

        if (empty($data['id']) || empty($data['address'])) {
            Log::error('Breet generateDepositAddress: incomplete data', [
                'assetId' => $assetId,
                'data'    => $data,
            ]);
            throw new \Exception(
                'Breet returned incomplete wallet data. Response: ' . json_encode($body)
            );
        }

        return [
            'wallet_id' => $data['id'],                // MongoDB ObjectId — for GET /trades/wallets/{id}
            'vault_id'  => (string) ($data['vaultId'] ?? ''), // numeric string — matched in webhooks
            'address'   => $data['address'],           // deposit address shown to user
            'raw'       => $data,                      // full data for audit / provider_response
        ];
    }

    /**
     * GET /trades/wallets/{id}
     *
     * Fetch a Breet wallet by its MongoDB wallet ID (data.id from generateDepositAddress).
     * Used by PollSellStatus as a sanity check.
     *
     * Response shape: { data: { id, vaultId, address, isActive, ... } }
     */
    public function getWallet(string $walletMongoId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/trades/wallets/{$walletMongoId}");

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            throw new \Exception(
                'Unable to fetch wallet: ' . ($body['message'] ?? 'unknown error')
            );
        }

        return $body['data'] ?? [];
    }

    /**
     * PUT /trades/wallets/{walletId}/bank
     *
     * Update the linked bank account on an existing wallet address.
     * walletId = the MongoDB wallet ID (breet_wallet_id on your transaction).
     */
    public function updateWalletBank(
        string $walletMongoId,
        string $bankId,
        string $accountNumber,
        bool   $autoSettlement = true,
    ): array {
        $response = $this->http()->put(
            "{$this->baseUrl}/trades/wallets/{$walletMongoId}/bank",
            [
                'bankId'        => $bankId,
                'accountNumber' => $accountNumber,
                'autoSettlement'=> $autoSettlement,
            ]
        );

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            throw new \Exception($body['message'] ?? 'Failed to update wallet bank.');
        }

        return $body['data'] ?? [];
    }

    /**
     * PUT /trades/wallets/{walletId}/auto-settlement
     *
     * Enable or disable auto-settlement on an existing wallet address.
     * walletId = the MongoDB wallet ID.
     * The wallet MUST already have a linked bank account.
     */
    public function setAutoSettlement(string $walletMongoId, bool $enabled): array
    {
        $response = $this->http()->put(
            "{$this->baseUrl}/trades/wallets/{$walletMongoId}/auto-settlement",
            ['autoSettlement' => $enabled]
        );

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            throw new \Exception($body['message'] ?? 'Failed to update auto-settlement.');
        }

        return $body['data'] ?? [];
    }

    // ── TRANSACTIONS ──────────────────────────────────────────────────────────

    /**
     * GET /trades/transactions/{id}
     *
     * Fetch a Breet trade transaction by its ID.
     * id = the Breet transaction ID sent in webhook payloads as `id`
     *      (stored on your transaction as provider_reference).
     *
     * Key response fields used for settlement:
     *   status          — pending | completed | flagged
     *   amount          — NGN gross amount (before markup deduction)
     *   amountSettled   — final NGN paid to bank (after markup) — use this
     *   feeAmount       — Breet platform fee in NGN
     *   rate            — NGN/USD rate at trade time
     *   settlementRate  — NGN/USD rate used for settlement (may differ)
     *   cryptoReceived  — crypto actually received on-chain
     *   txHash          — blockchain tx hash
     *   flagFeeUSD      — resolution fee if flagged (0 on happy path)
     *   markupPercent   — your configured markup %
     *   markupAmount    — NGN deducted as markup
     *   walletCredited  — bool: whether your Breet wallet balance was credited
     */
    public function getTransaction(string $breetTransactionId): array
    {
        $response = $this->http()->get(
            "{$this->baseUrl}/trades/transactions/{$breetTransactionId}"
        );

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            throw new \Exception(
                'Unable to fetch Breet transaction: ' . ($body['message'] ?? 'unknown error')
            );
        }

        return $body['data'] ?? [];
    }

    // ── BANKS ─────────────────────────────────────────────────────────────────

    /**
     * GET /payments/banks
     * Returns list of supported banks with their bankId values.
     * bankId is what you pass to generateDepositAddress().
     */
    public function getBanks(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/payments/banks");

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            throw new \Exception('Unable to fetch bank list.');
        }

        return $body['data'] ?? [];
    }

    // ── WEBHOOKS ──────────────────────────────────────────────────────────────

    /**
     * Verify a Breet webhook request.
     *
     * Breet uses a PLAIN SECRET in the x-webhook-secret header —
     * NOT an HMAC signature. Simply compare the header value
     * against your stored secret using hash_equals().
     *
     * The verifyWebhookSignature() method below is kept for backwards
     * compatibility but is NOT what Breet uses — do not call it for trade
     * webhooks.
     */
    public function verifyWebhookSecret(string $incomingSecret): bool
    {
        $expected = config('services.breet.webhook_secret', '');
        if (empty($expected)) return false;
        return hash_equals($expected, $incomingSecret);
    }

    /**
     * @deprecated Breet does NOT use HMAC signatures for webhooks.
     *             Use verifyWebhookSecret() instead.
     */
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

        return $response->json('data') ?? [];
    }

    public function createSwapOrder(
        string $fromAsset,
        string $fromNetwork,
        string $toAsset,
        float  $amount,
        string $reference,
        string $destinationAddress,
    ): array {
        $response = $this->http()->post("{$this->baseUrl}/swap", [
            'from_asset'          => strtolower($fromAsset),
            'from_network'        => strtolower($fromNetwork),
            'to_asset'            => strtolower($toAsset),
            'amount'              => $amount,
            'reference'           => $reference,
            'destination_address' => $destinationAddress,
        ]);

        if ($response->failed()) {
            throw new \Exception($response->json('message') ?? 'Failed to create swap order.');
        }

        return $response->json('data') ?? [];
    }

    // ── PAYOUT / WITHDRAWAL ──────────────────────────────────────────────────

    /**
     * POST /payments/withdraw
     * 
     * Create a withdrawal/payout to a user's bank account
     * 
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function createWithdrawal(array $data): array
    {
        $required = ['bankCode', 'accountNumber', 'amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        $payload = [
            'bankCode' => $data['bankCode'],
            'accountNumber' => $data['accountNumber'],
            'accountName' => $data['accountName'] ?? '',
            'amount' => (float) $data['amount'],
            'currency' => strtoupper($data['currency'] ?? 'NGN'),
            'narration' => $data['narration'] ?? 'PayYigi withdrawal',
            'reference' => $data['reference'] ?? 'payyigi_' . uniqid(),
        ];

        $response = $this->http()->post(
            "{$this->baseUrl}/payments/withdraw",
            $payload
        );

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            Log::error('Breet withdrawal failed', [
                'reference' => $data['reference'] ?? null,
                'response' => $body,
            ]);
            throw new \Exception('Breet withdrawal failed: ' . ($body['message'] ?? $response->body()));
        }

        return $body['data'] ?? [];
    }

    /**
     * GET /payments/withdraw/{id}
     * 
     * Check the status of a withdrawal
     * 
     * @param string $withdrawalId
     * @return array
     * @throws \Exception
     */
    public function getWithdrawalStatus(string $withdrawalId): array
    {
        $response = $this->http()->get(
            "{$this->baseUrl}/payments/withdraw/{$withdrawalId}"
        );

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            throw new \Exception('Failed to get withdrawal status: ' . ($body['message'] ?? $response->body()));
        }

        return $body['data'] ?? [];
    }
}