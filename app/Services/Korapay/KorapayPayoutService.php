<?php

namespace App\Services\Korapay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KorapayPayoutService
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
     * Disburse NGN directly to user's bank account via Korapay.
     * Korapay debits your merchant wallet and sends to user's bank instantly.
     *
     * POST /merchant/api/v1/transactions/disburse
     */
    public function disburse(
        string $reference,
        float  $amount,
        string $bankCode,
        string $accountNumber,
        string $accountName,
        string $customerEmail,
        string $narration = 'PayYigi Withdrawal',
    ): array {
        $payload = [
            'reference'   => $reference,
            'destination' => [
                'type'         => 'bank_account',
                'amount'       => round($amount, 2),
                'currency'     => 'NGN',
                'narration'    => $narration,
                'bank_account' => [
                    'bank'    => $bankCode,
                    'account' => $accountNumber,
                ],
                'customer'     => [
                    'name'  => $accountName,
                    'email' => $customerEmail,
                ],
            ],
        ];

        $response = $this->http()->post("{$this->baseUrl}/transactions/disburse", $payload);

        Log::info('Korapay disburse initiated', [
            'reference'   => $reference,
            'amount'      => $amount,
            'status_code' => $response->status(),
        ]);

        // IMPORTANT: On 5xx errors, DO NOT treat as failed.
        // Always verify via getPayoutStatus() first before marking failed.
        if ($response->serverError()) {
            Log::warning('Korapay disburse server error — verifying status', [
                'reference' => $reference,
                'status'    => $response->status(),
            ]);
            throw new \RuntimeException('server_error:' . $reference);
        }

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Payout initiation failed.';
            Log::error('Korapay disburse failed', [
                'reference' => $reference,
                'error'     => $error,
                'response'  => $response->json(),
            ]);
            throw new \Exception($error);
        }

        return $response->json('data');
    }

    /**
     * Verify a payout status — always call this on server errors
     * before deciding to retry or mark as failed.
     *
     * Korapay docs strongly recommend this to avoid double payouts.
     */
    public function getPayoutStatus(string $reference): array
    {
        $response = $this->http()->get("{$this->baseUrl}/transactions/{$reference}");

        if ($response->failed()) {
            throw new \Exception('Unable to verify payout status.');
        }

        return $response->json('data');
    }

    /**
     * Verify Korapay webhook signature.
     * Korapay signs ONLY the data object using HMAC SHA256.
     *
     * Header: x-korapay-signature
     */
    public function verifyWebhookSignature(array $data, string $signature): bool
    {
        $expected = hash_hmac('sha256', json_encode($data), $this->secretKey);
        return hash_equals($expected, $signature);
    }
}