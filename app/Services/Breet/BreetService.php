<?php

namespace App\Services\Breet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BreetService
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->baseUrl   = config('services.breet.base_url', 'https://api.breet.app/v1');
        $this->apiKey    = config('services.breet.api_key');
        $this->apiSecret = config('services.breet.api_secret');
    }

    // ─── Rates ───────────────────────────────────────────────────────────────

    /**
     * Get current market rate for a crypto asset.
     * Returns NGN rate per 1 unit of the asset.
     */
    public function getRate(string $asset): array
    {
        $response = $this->request('GET', "/rates/{$asset}/NGN");

        return [
            'asset'       => strtoupper($asset),
            'currency'    => 'NGN',
            'market_rate' => (float) $response['data']['rate'],
            'fetched_at'  => now()->toISOString(),
        ];
    }

    /**
     * Get rates for all supported assets at once.
     */
    public function getAllRates(): array
    {
        $response = $this->request('GET', '/rates?currency=NGN');
        return $response['data'] ?? [];
    }

    // ─── Sell Orders ─────────────────────────────────────────────────────────

    /**
     * Create a sell order on Breet.
     * Breet generates a unique deposit address for the user to send crypto to.
     *
     * @param string $asset        e.g. BTC, USDT, SOL
     * @param string $network      e.g. bitcoin, trc20, erc20, bep20, solana
     * @param float  $amount       crypto amount the user will send
     * @param string $reference    our internal transaction reference
     * @param array  $bankAccount  ['account_number', 'bank_code', 'account_name']
     */
    public function createSellOrder(
        string $asset,
        string $network,
        float  $amount,
        string $reference,
        array  $bankAccount,
    ): array {
        $response = $this->request('POST', '/orders/sell', [
            'asset'          => strtolower($asset),
            'network'        => strtolower($network),
            'amount'         => $amount,
            'reference'      => $reference,
            'bank_account'   => [
                'account_number' => $bankAccount['account_number'],
                'bank_code'      => $bankAccount['bank_code'],
                'account_name'   => $bankAccount['account_name'],
            ],
        ]);

        return $response['data'];
    }

    /**
     * Get status of an existing order.
     */
    public function getOrder(string $breetOrderId): array
    {
        $response = $this->request('GET', "/orders/{$breetOrderId}");
        return $response['data'];
    }

    // ─── Withdrawals ─────────────────────────────────────────────────────────

    /**
     * Initiate a bank payout via Breet.
     * For automatic withdrawal — Breet sends NGN to the user's bank account.
     */
    public function initiateWithdrawal(
        float  $amount,
        string $reference,
        array  $bankAccount,
    ): array {
        $response = $this->request('POST', '/payouts', [
            'amount'    => $amount,
            'currency'  => 'NGN',
            'reference' => $reference,
            'bank_account' => [
                'account_number' => $bankAccount['account_number'],
                'bank_code'      => $bankAccount['bank_code'],
                'account_name'   => $bankAccount['account_name'],
            ],
        ]);

        return $response['data'];
    }

    // ─── Webhook Validation ───────────────────────────────────────────────────

    /**
     * Validate the webhook signature from Breet.
     * Breet signs payloads with HMAC-SHA256.
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, config('services.breet.webhook_secret'));
        return hash_equals($expected, $signature);
    }

    // ─── Bank Utilities ───────────────────────────────────────────────────────

    /**
     * Verify a Nigerian bank account via Breet.
     * Returns account name for confirmation before saving.
     */
    public function verifyBankAccount(string $accountNumber, string $bankCode): array
    {
        $response = $this->request('POST', '/banks/verify', [
            'account_number' => $accountNumber,
            'bank_code'      => $bankCode,
        ]);

        return $response['data'];
    }

    /**
     * Get list of Nigerian banks supported.
     */
    public function getBanks(): array
    {
        $response = $this->request('GET', '/banks');
        return $response['data'] ?? [];
    }

    // ─── HTTP Helper ──────────────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $http = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'x-api-secret'  => $this->apiSecret,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->timeout(30);

        $response = match (strtoupper($method)) {
            'GET'    => $http->get($url, $data),
            'POST'   => $http->post($url, $data),
            'PUT'    => $http->put($url, $data),
            'DELETE' => $http->delete($url, $data),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        Log::info('Breet API request', [
            'method'      => $method,
            'endpoint'    => $endpoint,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Breet API request failed.';
            Log::error('Breet API error', [
                'endpoint' => $endpoint,
                'status'   => $response->status(),
                'error'    => $error,
                'body'     => $response->json(),
            ]);
            throw new \Exception($error, $response->status());
        }

        return $response->json();
    }
}
