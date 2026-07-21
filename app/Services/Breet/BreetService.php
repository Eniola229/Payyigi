<?php

namespace App\Services\Breet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
    public function getBanks(string $currency = 'ngn'): array
    {
        $response = $this->http()->get("{$this->baseUrl}/payments/banks", [
            'currency' => $currency,
        ]);

        $body = $response->json();

        if ($response->failed() || empty($body['success'])) {
            Log::error('Breet getBanks failed', [
                'status'   => $response->status(),
                'currency' => $currency,
                'body'     => $body,
            ]);
            throw new \Exception($body['message'] ?? 'Unable to fetch bank list.');
        }

        return $body['data'] ?? [];
    }
    // ── BANK ID RESOLUTION ──────────────────────────────────────────────────

    /**
     * GET /payments/banks
     *
     * Resolve Breet's numeric bank `id` from a bank name (e.g. the name
     * stored on bank_accounts/transactions from Korapay's bank list).
     *
     * IMPORTANT: Breet's `id` is its own internal index (0, 1, 2...), not a
     * CBN/NIP code — so Korapay's bank_code can NOT be passed through
     * directly to verifyBankAccount()/addBank(). We match by bank name
     * instead. Cached for an hour since the bank list barely changes.
     */
    public function resolveBankId(string $bankName): ?string
    {
        $banks = Cache::remember('breet:banks:ngn', 3600, fn () => $this->getBanks());

        $needle = $this->normalizeBankName($bankName);

        // Exact match first
        foreach ($banks as $bank) {
            if ($this->normalizeBankName($bank['name'] ?? '') === $needle) {
                return (string) $bank['id'];
            }
        }

        // Fallback: partial match (handles "UBA" vs "United Bank For Africa")
        foreach ($banks as $bank) {
            $candidate = $this->normalizeBankName($bank['name'] ?? '');
            if ($candidate !== '' && (str_contains($needle, $candidate) || str_contains($candidate, $needle))) {
                return (string) $bank['id'];
            }
        }

        Log::error('Breet resolveBankId: no match found', ['bank_name' => $bankName]);

        return null;
    }

    private function normalizeBankName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace(['plc', 'nigeria', 'limited', 'ltd', 'bank'], '', $name);
        return trim(preg_replace('/[^a-z0-9]+/', '', $name));
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

// ── PAYOUT / WITHDRAWAL ──────────────────────────────────────────────────

/**
 * POST /payments/banks/validate
 * Verify a bank account before saving it.
 */
public function verifyBankAccount(string $bankId, string $accountNumber): array
{
    $response = $this->http()->post("{$this->baseUrl}/payments/banks/validate", [
        'id'            => $bankId,
        'accountNumber' => $accountNumber,
    ]);

    $body = $response->json();

    if ($response->failed() || empty($body['success'])) {
        throw new \Exception($body['message'] ?? 'Failed to verify bank account.');
    }

    return $body['data'] ?? [];
}

/**
 * POST /payments/banks/add
 * Save a validated bank account to your integration. Returns the saved
 * bank's Mongo _id — this is what you pass to withdrawToBank(), NOT the
 * raw bankCode/accountNumber.
 *
 * Handles the "account already exists" case by looking it up instead.
 */
public function addBank(string $bankId, string $accountNumber, ?string $narration = null): array
{
    $response = $this->http()->post("{$this->baseUrl}/payments/banks/add", [
        'id'            => $bankId,
        'accountNumber' => $accountNumber,
        'narration'     => substr($narration ?? 'PayYigi withdrawal', 0, 32),
    ]);

    $body = $response->json();

    if ($response->failed() || empty($body['success'])) {
        $message = $body['message'] ?? '';

        if (stripos($message, 'already exists') !== false) {
            $saved = $this->findSavedBank($bankId, $accountNumber);
            if ($saved) {
                return $saved;
            }
        }

        Log::error('Breet addBank failed', ['bankId' => $bankId, 'body' => $body]);
        throw new \Exception($message ?: 'Failed to save bank account.');
    }

    return $body['data'] ?? [];
}

/**
 * GET /payments/banks/saved (or whatever the list endpoint is — confirm
 * against "Fetch Saved Banks" in docs) — used to recover the saved bank
 * ID when addBank() reports the account already exists.
 */
public function findSavedBank(string $bankId, string $accountNumber): ?array
{
    $response = $this->http()->get("{$this->baseUrl}/payments/banks/saved");
    $body = $response->json();

    if ($response->failed() || empty($body['success'])) {
        return null;
    }

    foreach (($body['data'] ?? []) as $bank) {
        if (($bank['bankId'] ?? null) == $bankId
            && ($bank['accountNumber'] ?? null) === $accountNumber) {
            return $bank;
        }
    }

    return null;
}

/**
 * POST /payments/withdraw/bank/{id}
 *
 * Initiate fiat (NGN|GHS) payout to a SAVED bank account.
 * $savedBankId = the Mongo _id returned by addBank(), NOT the numeric
 * bankId and NOT the raw accountNumber.
 *
 * Requires a withdrawal PIN set on the Breet dashboard — store this in
 * config('services.breet.withdrawal_pin').
 */
public function withdrawToBank(
    string $savedBankId,
    float  $amount,
    string $externalId,
    ?string $narration = null,
): array {
    $payload = [
        'amount'     => $amount,
        'narration'  => substr($narration ?? 'PayYigi payout', 0, 32),
        'externalId' => $externalId,
    ];

    $pin = config('services.breet.withdrawal_pin');
    if (!empty($pin)) {
        $payload['pin'] = $pin;
    }

    $response = $this->http()->post(
        "{$this->baseUrl}/payments/withdraw/bank/{$savedBankId}",
        $payload
    );

    $body = $response->json();

    if ($response->failed() || empty($body['success'])) {
        Log::error('Breet withdrawToBank failed', [
            'externalId' => $externalId,
            'response'   => $body,
        ]);
        throw new \Exception('Breet withdrawal failed: ' . ($body['message'] ?? $response->body()));
    }

    return $body['data'] ?? []; // { id: <withdrawalId> }
}

/**
 * GET /payments/withdrawal/{id}   ← singular "withdrawal", not "withdraw"
 * Check the status of a withdrawal by its ID or externalId.
 */
public function getWithdrawalStatus(string $withdrawalId): array
{
    $response = $this->http()->get("{$this->baseUrl}/payments/withdrawal/{$withdrawalId}");

    $body = $response->json();

    if ($response->failed() || empty($body['success'])) {
        throw new \Exception('Failed to get withdrawal status: ' . ($body['message'] ?? $response->body()));
    }

    return $body['data'] ?? [];
}

    /**
     * Find existing wallet or create new one for a user/asset
     * Breet wallets are permanent and reusable - this method handles both cases
     */
    public function findOrCreateWallet(
        string $assetId,
        string $label,
        ?string $bankId = null,
        ?string $accountNumber = null,
        ?string $narration = null,
        bool $autoSettlement = true,
    ): array 
    {
        try {
            // First, try to create a new wallet
            return $this->generateDepositAddress(
                assetId: $assetId,
                label: $label,
                bankId: $bankId,
                accountNumber: $accountNumber,
                narration: $narration,
                autoSettlement: $autoSettlement
            );
        } catch (\Exception $e) {
            // If error contains "already exists", try to find the existing wallet
            if (stripos($e->getMessage(), 'already exists') !== false) {
                Log::info('Wallet already exists, attempting to find existing one', [
                    'assetId' => $assetId,
                    'label' => $label
                ]);
                
                // Since Breet doesn't provide a direct "list wallets by label" endpoint,
                // the best approach is to query your database for the existing wallet info
                // and fetch it from Breet using the stored wallet ID
                $transaction = Transaction::where('breet_wallet_id', 'like', '%')
                    ->where('crypto_asset', $this->getAssetSymbolFromId($assetId))
                    ->latest()
                    ->first();
                
                if ($transaction && $transaction->breet_wallet_id) {
                    return $this->getWallet($transaction->breet_wallet_id);
                }
                
                // If not found in DB, the wallet exists in Breet but we don't have the ID.
                // We need to retrieve it - check if Breet has a "get wallet by label" endpoint
                // or consider storing wallet IDs more reliably in your DB
                
                throw new \Exception('Wallet exists but could not be retrieved. Please contact support.');
            }
            throw $e;
        }
    }

    /**
     * Helper to get asset symbol from ID
     */
    private function getAssetSymbolFromId(string $assetId): string
    {
        $asset = BreetAsset::find($assetId);
        return $asset ? strtoupper($asset->symbol) : '';
    }

    /**
     * Resolve Breet's bank `id` from a stored bank_code.
     *
     * NOTE: `bank_code` on bank_accounts/transactions is NOT a CBN/NIP code —
     * it's already Breet's own numeric bank `id`, captured at bank-account
     * creation time. We don't blindly trust it though; we confirm it still
     * exists in Breet's current bank list before using it (ids could
     * theoretically shift or be retired).
     */
    public function resolveBankIdFromCode(string $bankCode): ?string
    {
        $banks = Cache::remember('breet:banks:ngn', 3600, fn () => $this->getBanks());

        foreach ($banks as $bank) {
            if ((string) ($bank['id'] ?? '') === (string) $bankCode) {
                return (string) $bank['id'];
            }
        }

        Log::error('Breet resolveBankIdFromCode: no match found', ['bank_code' => $bankCode]);

        return null;
    }
}