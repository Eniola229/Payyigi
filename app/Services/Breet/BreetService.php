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

    // ── RATES ─────────────────────────────────────────────────────────────────

    public function getSellRate(string $asset): array
    {
        $response = $this->http()->get("{$this->baseUrl}/rates/{$asset}");

        if ($response->failed()) {
            Log::error('Breet getSellRate failed', ['asset' => $asset, 'response' => $response->json()]);
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

    // ── SELL ──────────────────────────────────────────────────────────────────

    /**
     * Create a sell order on Breet.
     * Breet generates a unique deposit address per transaction.
     * User sends crypto to that address.
     * Breet converts and pays NGN to the provided bank account.
     * We pass our COMPANY bank account so NGN lands with us,
     * then we credit user's wallet on webhook confirmation.
     */
    public function createSellOrder(
        string $asset,
        string $network,
        float  $amount,
        string $reference,
        string $accountNumber,
        string $bankCode,
        string $accountName,
    ): array {
        $payload = [
            'asset'          => strtolower($asset),
            'network'        => strtolower($network),
            'amount'         => $amount,
            'reference'      => $reference,
            'bank_code'      => $bankCode,
            'account_number' => $accountNumber,
            'account_name'   => $accountName,
        ];

        $response = $this->http()->post("{$this->baseUrl}/transactions/sell", $payload);

        Log::info('Breet createSellOrder', [
            'reference'   => $reference,
            'asset'       => $asset,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to create sell order.';
            Log::error('Breet createSellOrder failed', [
                'reference' => $reference,
                'error'     => $error,
            ]);
            throw new \Exception($error);
        }

        return $response->json('data');
    }

    /**
     * Get sell order status by our reference.
     * Status values: pending | processing | completed | failed
     */
    public function getSellStatus(string $reference): array
    {
        $response = $this->http()->get("{$this->baseUrl}/transactions/{$reference}");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch transaction status.');
        }

        return $response->json('data');
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
            $error = $response->json('message') ?? 'Failed to create swap order.';
            throw new \Exception($error);
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

    // ── FEE CALCULATION ───────────────────────────────────────────────────────

    /**
     * Calculate all fees for a sell transaction.
     *
     * Breet's fee: 0.5% of the NGN payout (their cut)
     * Platform fee: tiered spread baked into displayed rate
     *   - < 100 units  → 3.5% spread
     *   - >= 100 units → 2.5% spread
     */
    public function calculateFees(float $cryptoAmount, array $rateData): array
    {
        $marketRate    = (float) $rateData['rate'];
        $spreadPercent = $this->getSpreadPercent($cryptoAmount);
        $displayRate   = round($marketRate * (1 - ($spreadPercent / 100)), 2);
        $grossNgn      = round($cryptoAmount * $displayRate, 2);
        $marketNgn     = round($cryptoAmount * $marketRate, 2);

        // Breet's 0.5% fee — deducted from gross NGN
        $providerFee   = round($grossNgn * 0.005, 2);

        // Our platform fee
        $platformFee   = round($grossNgn * (config('payyigi.platform_fee_percent', 0.5) / 100), 2);

        $totalFee      = round($providerFee + $platformFee, 2);
        $netNgn        = round($grossNgn - $totalFee, 2);
        $spreadAmount  = round($marketNgn - $grossNgn, 2);

        return [
            'market_rate'    => $marketRate,
            'display_rate'   => $displayRate,
            'spread_percent' => $spreadPercent,
            'gross_ngn'      => $grossNgn,
            'provider_fee'   => $providerFee,   // Breet's 0.5%
            'platform_fee'   => $platformFee,   // PayYigi's fee
            'total_fee'      => $totalFee,
            'net_ngn'        => $netNgn,         // credited to user wallet
            'spread_amount'  => $spreadAmount,   // PayYigi spread revenue
        ];
    }

    private function getSpreadPercent(float $cryptoAmount): float
    {
        foreach (config('payyigi.platform_fee_tiers') as $tier) {
            if ($cryptoAmount >= $tier['min'] && (is_null($tier['max']) || $cryptoAmount < $tier['max'])) {
                return $tier['percent'];
            }
        }
        return 3.5;
    }
}