<?php

namespace App\Services\Korapay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KorapayService
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl   = config('services.korapay.base_url', 'https://api.korapay.com/merchant/api/v1');
        $this->secretKey = config('services.korapay.secret_key');
    }

    /**
     * Lookup NIN via Korapay Identity API.
     *
     * Returns the full NIN data including phone_number registered to that NIN.
     * We use that phone_number to send the OTP — proving ownership.
     *
     * Optionally validates first_name, last_name, date_of_birth against NIMC records.
     *
     * @throws \Exception on API failure
     */
    public function verifyNin(
        string  $nin,
        bool    $validateData  = false,
        ?string $firstName     = null,
        ?string $lastName      = null,
        ?string $dateOfBirth   = null,
    ): array {
        $payload = [
            'id'                   => $nin,
            'verification_consent' => true,
        ];

        // Optional data matching
        if ($validateData && $firstName && $lastName && $dateOfBirth) {
            $payload['validation'] = [
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'date_of_birth' => $dateOfBirth,
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type'  => 'application/json',
        ])->post("{$this->baseUrl}/identities/ng/nin", $payload);

        Log::info('Korapay NIN lookup', [
            'nin_last4'   => substr($nin, -4),
            'status_code' => $response->status(),
        ]);

        if ($response->failed()) {
            $error = $response->json('message') ?? 'NIN verification failed.';
            Log::error('Korapay NIN lookup failed', [
                'nin_last4' => substr($nin, -4),
                'error'     => $error,
                'response'  => $response->json(),
            ]);
            throw new \Exception($error);
        }

        $body = $response->json();

        if (!($body['status'] ?? false)) {
            throw new \Exception($body['message'] ?? 'NIN lookup was unsuccessful.');
        }

        return $body['data'];
    }
}
