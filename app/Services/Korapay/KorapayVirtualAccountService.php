<?php

namespace App\Services\Korapay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KorapayVirtualAccountService
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl   = config('services.korapay.base_url', 'https://api.korapay.com/merchant/api/v1');
        $this->secretKey = config('services.korapay.secret_key');
    }

    private function http()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type'  => 'application/json',
        ])->timeout(30);
    }

    /**
     * Create a permanent virtual bank account for a user.
     * User sends any amount to this account anytime — Korapay notifies via webhook.
     *
     * POST /merchant/api/v1/virtual-bank-account
     */
    public function createVirtualAccount(
        string $name,
        string $email,
        string $reference,
        string $bvnOrNin,
        string $bankCode = '035', // Wema Bank (default — Korapay supported)
    ): array {
        $payload = [
            'account_name' => $name,
            'account_reference' => $reference,
            'permanent'    => true, // fixed — doesn't expire
            'bank_code'    => $bankCode,
            'customer'     => [
                'name'  => $name,
                'email' => $email,
            ],
            'kyc'          => [
                'bvn' => $bvnOrNin, // Korapay requires BVN or NIN for virtual accounts
            ],
        ];

        $response = $this->http()->post("{$this->baseUrl}/virtual-bank-account", $payload);

        Log::info('Korapay createVirtualAccount', [
            'reference'   => $reference,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to create virtual account.';
            Log::error('Korapay createVirtualAccount failed', [
                'reference' => $reference,
                'error'     => $error,
                'response'  => $response->json(),
            ]);
            throw new \Exception($error);
        }

        return $response->json('data');
    }

    /**
     * Get virtual account details by reference.
     * GET /merchant/api/v1/virtual-bank-account/:reference
     */
    public function getVirtualAccount(string $reference): array
    {
        $response = $this->http()->get("{$this->baseUrl}/virtual-bank-account/{$reference}");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch virtual account details.');
        }

        return $response->json('data');
    }

    /**
     * Verify a charge/payment by reference (call after receiving webhook).
     * GET /merchant/api/v1/charges/:reference
     */
    public function verifyCharge(string $reference): array
    {
        $response = $this->http()->get("{$this->baseUrl}/charges/{$reference}");

        if ($response->failed()) {
            throw new \Exception('Unable to verify charge.');
        }

        return $response->json('data');
    }

    /**
     * Verify Korapay webhook signature.
     * Korapay signs the entire payload using HMAC SHA256.
     * Header: x-korapay-signature
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $rawPayload, $this->secretKey);
        return hash_equals($expected, $signature);
    }
}