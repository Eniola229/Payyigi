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

    /**
     * Apply spread to Breet's market rate.
     * Sell: user gets LESS than market (we keep the difference as spread revenue)
     * e.g. market = ₦1500, spread 4% → user gets ₦1440 per unit
     */
    public function applySpread(float $marketRate, float $spreadPercent = null): float
    {
        $spread = $spreadPercent ?? config('payyigi.spread_percent', 4);
        return round($marketRate * (1 - ($spread / 100)), 2);
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
    public function calculateFees(float $cryptoAmount, float $marketRate): array
    {
        $spreadPercent   = config('payyigi.spread_percent', 4);
        $platformFeePct  = config('payyigi.platform_fee_percent', 0.5);
        $breetFeePct     = config('payyigi.breet_fee_percent', 0.5);

        $displayRate  = $this->applySpread($marketRate, $spreadPercent);
        $grossNgn     = $this->calculateNgnOut($cryptoAmount, $displayRate);
        $spreadAmount = $this->calculateNgnOut($cryptoAmount, $marketRate) - $grossNgn;

        $platformFee  = round($grossNgn * ($platformFeePct / 100), 2);
        $breetFee     = round($grossNgn * ($breetFeePct / 100), 2);
        $totalFee     = round($platformFee + $breetFee, 2);
        $netNgn       = round($grossNgn - $totalFee, 2);

        return [
            'gross_ngn'     => $grossNgn,
            'platform_fee'  => $platformFee,
            'breet_fee'     => $breetFee,
            'total_fee'     => $totalFee,
            'net_ngn'       => $netNgn,       // credited to wallet
            'spread_amount' => $spreadAmount,  // our spread profit
            'display_rate'  => $displayRate,
            'market_rate'   => $marketRate,
        ];
    }
}
