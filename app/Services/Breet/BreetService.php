<?php

namespace App\Services\Breet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BreetService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.breet.base_url');
        $this->apiKey  = config('services.breet.api_key');
    }

    private function http()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])->timeout(30);
    }

    public function getRate(string $asset): array
    {
        $response = $this->http()->get("{$this->baseUrl}/rates/{$asset}");

        if ($response->failed()) {
            Log::error('Breet getRate failed', ['asset' => $asset, 'response' => $response->json()]);
            throw new \Exception("Unable to fetch rate for {$asset}. Please try again.");
        }

        return $response->json('data');
    }

    public function getAllRates(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/rates");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch rates. Please try again.');
        }

        return $response->json('data');
    }

    public function createSellOrder(
        string $asset,
        string $network,
        float  $amount,
        array  $bankAccount,
        string $reference,
    ): array {
        $payload = [
            'asset'          => strtolower($asset),
            'network'        => strtolower($network),
            'amount'         => $amount,
            'bank_code'      => $bankAccount['bank_code']      ?? null,
            'account_number' => $bankAccount['account_number'] ?? null,
            'account_name'   => $bankAccount['account_name']   ?? null,
            'reference'      => $reference,
        ];

        $response = $this->http()->post("{$this->baseUrl}/transactions/sell", $payload);

        Log::info('Breet createSellOrder', [
            'reference'   => $reference,
            'asset'       => $asset,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to create sell order.';
            Log::error('Breet createSellOrder failed', ['reference' => $reference, 'error' => $error]);
            throw new \Exception($error);
        }

        return $response->json('data');
    }

    public function getOrder(string $breetOrderId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/transactions/{$breetOrderId}");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch order status.');
        }

        return $response->json('data');
    }

    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        $secret   = config('services.breet.webhook_secret');
        $expected = hash_hmac('sha256', $rawPayload, $secret);
        return hash_equals($expected, $signature);
    }

    public function calculateNgnOut(float $cryptoAmount, float $rate): float
    {
        return round($cryptoAmount * $rate, 2);
    }

    /**
     * Calculate all fees for a sell transaction.
     *
     * Given NGN amount BEFORE fees:
     *   - Platform fee: 0.5% → goes to PayYigi
     *   - Breet fee:    0.5% → goes to Breet
     *   - Total cost:   1.0% deducted from what user receives
     *
     * Spread revenue is SEPARATE — already baked into the displayed rate.
     * Fees are deducted from the NGN payout to the user's wallet.
     *
     * Returns:
     *   gross_ngn     — NGN at displayed rate before fees
     *   platform_fee  — PayYigi's 0.5% cut
     *   breet_fee     — Breet's 0.5% cut
     *   total_fee     — combined 1%
     *   net_ngn       — what user actually receives in wallet
     *   spread_amount — PayYigi's spread revenue (from rate difference)
     */
    public function applySpread(float $marketRate, float $spreadPercent): float
    {
        return round($marketRate * (1 - ($spreadPercent / 100)), 2);
    }

    private function getPlatformFeePercent(float $cryptoAmount): float
    {
        foreach (config('payyigi.platform_fee_tiers') as $tier) {
            $meetsMin = $cryptoAmount >= $tier['min'];
            $meetsMax = is_null($tier['max']) || $cryptoAmount < $tier['max'];

            if ($meetsMin && $meetsMax) {
                return $tier['percent'];
            }
        }

        return 3.5; // fallback
    }

    public function calculateFees(float $cryptoAmount, float $marketRate): array
    {
        $spreadPercent = $this->getPlatformFeePercent($cryptoAmount);
        $displayRate   = $this->applySpread($marketRate, $spreadPercent);
        $grossNgn      = $this->calculateNgnOut($cryptoAmount, $displayRate);
        $breetFee      = round($grossNgn * (config('payyigi.breet_fee_percent', 0.5) / 100), 2);
        $netNgn        = round($grossNgn - $breetFee, 2);
        $spreadAmount  = round($this->calculateNgnOut($cryptoAmount, $marketRate) - $grossNgn, 2);

        return [
            'spread_percent' => $spreadPercent,
            'display_rate'   => $displayRate,
            'market_rate'    => $marketRate,
            'gross_ngn'      => $grossNgn,
            'breet_fee'      => $breetFee,
            'net_ngn'        => $netNgn,
            'spread_amount'  => $spreadAmount,
        ];
    }
}
