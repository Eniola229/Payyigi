<?php

namespace App\Services\LocalRamp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocalRampService
{
    private string $baseUrl;
    private string $secretKey;
    private string $publicKey;

    public function __construct()
    {
        $this->baseUrl   = config('services.localramp.base_url');
        $this->secretKey = config('services.localramp.secret_key');
        $this->publicKey = config('services.localramp.public_key');
    }

    // Public key for read-only endpoints (rates, limits, currencies, banks)
    private function publicHttp()
    {
        return Http::withHeaders([
            'x-auth-token' => $this->publicKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(30);
    }

    // Secret key for transactional endpoints (initiate, status, swap, balances)
    private function secretHttp()
    {
        return Http::withHeaders([
            'x-auth-token' => $this->secretKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(30);
    }

    // ── SELL (Off-ramp) ───────────────────────────────────────────────────────

    /**
     * Get sell rate for a crypto → fiat pair.
     * Returns rate per unit AND LocalRamp's flat processing fee in NGN.
     *
     * GET /transaction/sell/rate?from_currency=USDT&to_currency=NGN
     */
    public function getSellRate(string $fromCurrency, string $toCurrency = 'NGN'): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/sell/rate", [
            'from_currency' => strtoupper($fromCurrency),
            'to_currency'   => strtoupper($toCurrency),
        ]);

        if ($response->failed()) {
            Log::error('LocalRamp getSellRate failed', [
                'from' => $fromCurrency,
                'error' => $response->json('message'),
            ]);
            throw new \Exception("Unable to fetch rate for {$fromCurrency}. Please try again.");
        }

        // Returns: { rate: { amount: "733.86" }, fee: { amount: "300.00" } }
        return $response->json('data');
    }

    /**
     * Get sell limits for a crypto → fiat pair.
     * Returns min and max from_amount allowed.
     *
     * GET /transaction/sell/limits?from_currency=BTC&to_currency=NGN
     */
    public function getSellLimits(string $fromCurrency, string $toCurrency = 'NGN'): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/sell/limits", [
            'from_currency' => strtoupper($fromCurrency),
            'to_currency'   => strtoupper($toCurrency),
        ]);

        if ($response->failed()) {
            throw new \Exception("Unable to fetch limits for {$fromCurrency}.");
        }

        return $response->json('data');
    }

    /**
     * Get supported sell currencies.
     * GET /transaction/sell/currencies
     */
    public function getSellCurrencies(): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/sell/currencies");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch supported currencies.');
        }

        return $response->json('data');
    }

    /**
     * Get supported banks for NGN payouts.
     * GET /transaction/sell/supported-banks?country=NG
     */
    public function getSupportedBanks(string $country = 'NG'): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/sell/supported-banks", [
            'country' => $country,
        ]);

        if ($response->failed()) {
            throw new \Exception('Unable to fetch supported banks.');
        }

        return $response->json('data');
    }

    /**
     * Verify a bank account — resolves account name.
     * POST /transaction/sell/verify-bank
     */
    public function verifyBankAccount(string $accountNumber, string $bankCode, string $currency = 'NGN'): array
    {
        $response = $this->publicHttp()->post("{$this->baseUrl}/transaction/sell/verify-bank", [
            'account_number' => $accountNumber,
            'bank_code'      => $bankCode,
            'currency'       => $currency,
        ]);

        if ($response->failed()) {
            throw new \Exception($response->json('message') ?? 'Bank account verification failed.');
        }

        return $response->json('data');
    }

    /**
     * Initiate a sell (off-ramp) transaction.
     * LocalRamp sells from YOUR wallet balance and pays NGN to user's bank.
     *
     * POST /transaction/sell/initiate
     *
     * NOTE: This is custodial — your LocalRamp wallet must have the crypto balance.
     * Users send crypto to YOUR LocalRamp deposit address (get from dashboard).
     */
    public function initiateSell(
        string $reference,
        string $email,
        string $fromCurrency,
        float  $fromAmount,
        string $accountNumber,
        string $bankCode,
        string $toCurrency = 'NGN',
        string $countryCode = 'NG',
    ): array {
        $payload = [
            'tx_ext_reference' => $reference,
            'email'            => $email,
            'from_currency'    => strtoupper($fromCurrency),
            'to_currency'      => strtoupper($toCurrency),
            'country_code'     => $countryCode,
            'from_amount'      => (string) $fromAmount,
            'destination_type' => 'bank_account',
            'account_number'   => $accountNumber,
            'bank_code'        => $bankCode,
        ];

        $response = $this->secretHttp()->post("{$this->baseUrl}/transaction/sell/initiate", $payload);

        Log::info('LocalRamp initiateSell', [
            'reference'   => $reference,
            'from'        => $fromCurrency,
            'amount'      => $fromAmount,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to initiate sell.';
            Log::error('LocalRamp initiateSell failed', [
                'reference' => $reference,
                'error'     => $error,
            ]);
            throw new \Exception($error);
        }

        // Returns: { tx_ext_reference, reference, account_name, bank_name }
        return $response->json('data');
    }

    /**
     * Get sell transaction status by our reference (tx_ext_reference).
     * GET /transaction/sell/status/:ext_reference/ext
     *
     * Status values: pending | completed | failed
     */
    public function getSellStatus(string $extReference): array
    {
        $response = $this->secretHttp()->get("{$this->baseUrl}/transaction/sell/status/{$extReference}/ext");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch transaction status.');
        }

        return $response->json('data');
    }

    /**
     * Get sell transaction status by LocalRamp's reference.
     * GET /transaction/sell/status/:reference
     */
    public function getSellStatusByLocalRef(string $localReference): array
    {
        $response = $this->secretHttp()->get("{$this->baseUrl}/transaction/sell/status/{$localReference}");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch transaction status.');
        }

        return $response->json('data');
    }

    // ── SWAP (Crypto → Crypto) ────────────────────────────────────────────────

    /**
     * Get swap rate between two cryptocurrencies.
     * GET /transaction/swap/rate?from_currency=BTC&to_currency=USDT
     */
    public function getSwapRate(string $fromCurrency, string $toCurrency): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/swap/rate", [
            'from_currency' => strtoupper($fromCurrency),
            'to_currency'   => strtoupper($toCurrency),
        ]);

        if ($response->failed()) {
            throw new \Exception("Unable to fetch swap rate for {$fromCurrency} → {$toCurrency}.");
        }

        // Returns: { rate: { amount: "20452.7993" } }
        return $response->json('data');
    }

    /**
     * Get swap limits.
     * GET /transaction/swap/limits?from_currency=BTC&to_currency=USDT
     */
    public function getSwapLimits(string $fromCurrency, string $toCurrency): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/swap/limits", [
            'from_currency' => strtoupper($fromCurrency),
            'to_currency'   => strtoupper($toCurrency),
        ]);

        if ($response->failed()) {
            throw new \Exception('Unable to fetch swap limits.');
        }

        return $response->json('data');
    }

    /**
     * Initiate a crypto swap.
     * Swaps between currencies in YOUR LocalRamp wallet.
     * POST /transaction/swap/initiate
     */
    public function initiateSwap(string $fromCurrency, string $toCurrency, float $fromAmount): array
    {
        $payload = [
            'from_currency' => strtoupper($fromCurrency),
            'to_currency'   => strtoupper($toCurrency),
            'from_amount'   => (string) $fromAmount,
        ];

        $response = $this->secretHttp()->post("{$this->baseUrl}/transaction/swap/initiate", $payload);

        Log::info('LocalRamp initiateSwap', [
            'from'        => $fromCurrency,
            'to'          => $toCurrency,
            'amount'      => $fromAmount,
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'Failed to initiate swap.';
            throw new \Exception($error);
        }

        // Returns: { reference: "SWAP_hDUkDSE36MHkds79" }
        return $response->json('data');
    }

    /**
     * Get swap transaction status.
     * GET /transaction/swap/status/:reference
     *
     * State values: pending | completed
     */
    public function getSwapStatus(string $reference): array
    {
        $response = $this->secretHttp()->get("{$this->baseUrl}/transaction/swap/status/{$reference}");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch swap status.');
        }

        return $response->json('data');
    }

    // ── BUY (On-ramp) ─────────────────────────────────────────────────────────

    /**
     * Get buy quote — how much crypto user gets for NGN amount.
     * GET /transaction/buy/quote?sender_currency=NGN&receiver_currency=USDT_BSC&country_code=NG
     *
     * Formula: receiver_amount = ((sender_amount - processor_fee) / exchange_rate) - network_fee
     */
    public function getBuyQuote(string $receiverCurrency, string $senderCurrency = 'NGN', string $countryCode = 'NG'): array
    {
        $response = $this->publicHttp()->get("{$this->baseUrl}/transaction/buy/quote", [
            'sender_currency'   => strtoupper($senderCurrency),
            'receiver_currency' => strtoupper($receiverCurrency),
            'country_code'      => $countryCode,
        ]);

        if ($response->failed()) {
            throw new \Exception("Unable to fetch buy quote for {$receiverCurrency}.");
        }

        return $response->json('data');
    }

    // ── WALLET ────────────────────────────────────────────────────────────────

    /**
     * Get your LocalRamp wallet balances (all currencies).
     * GET /wallet/balances
     */
    public function getWalletBalances(): array
    {
        $response = $this->secretHttp()->get("{$this->baseUrl}/wallet/balances");

        if ($response->failed()) {
            throw new \Exception('Unable to fetch wallet balances.');
        }

        return $response->json('data.balances');
    }

    // ── FEE CALCULATION ───────────────────────────────────────────────────────

    /**
     * Calculate all fees for a sell transaction.
     *
     * LocalRamp's fee model:
     * - rate.amount = NGN per 1 unit of crypto (e.g. ₦733.86 per USDT)
     * - fee.amount  = flat LocalRamp processing fee in NGN (e.g. ₦300)
     *
     * Our platform fee (spread) is applied on TOP — we show user a lower rate.
     *
     * Flow:
     * 1. Get LocalRamp rate (market rate)
     * 2. Apply our spread → displayed rate
     * 3. Gross NGN = crypto_amount × displayed_rate
     * 4. LocalRamp fee = flat fee from API
     * 5. Platform fee = tiered % of gross NGN
     * 6. Net NGN to wallet = gross - localramp_fee - platform_fee
     */
    public function calculateFees(float $cryptoAmount, array $rateData): array
    {
        $marketRate     = (float) $rateData['rate']['amount'];
        $localRampFee   = (float) $rateData['fee']['amount']; // flat NGN fee

        // Pick spread tier based on crypto amount
        $spreadPercent  = $this->getSpreadPercent($cryptoAmount);
        $displayRate    = round($marketRate * (1 - ($spreadPercent / 100)), 2);

        $grossNgn       = round($cryptoAmount * $displayRate, 2);
        $marketNgn      = round($cryptoAmount * $marketRate, 2);

        // Our platform fee (tiered %)
        $platformFee    = round($grossNgn * (config('payyigi.platform_fee_percent', 0.5) / 100), 2);

        $totalFee       = round($localRampFee + $platformFee, 2);
        $netNgn         = round($grossNgn - $totalFee, 2);
        $spreadAmount   = round($marketNgn - $grossNgn, 2);

        return [
            'market_rate'    => $marketRate,
            'display_rate'   => $displayRate,
            'spread_percent' => $spreadPercent,
            'gross_ngn'      => $grossNgn,
            'localramp_fee'  => $localRampFee,  // LocalRamp's flat fee
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