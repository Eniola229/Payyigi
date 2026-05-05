<?php
namespace App\Services\Termii;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('services.termii.api_key');
        $this->baseUrl = config('services.termii.base_url');
    }

    /**
     * Send an OTP via voice call.
     * Termii generates the PIN internally and reads it aloud to the recipient.
     * Verify the PIN afterwards using the Termii Verify Token API.
     *
     * POST /api/sms/otp/send/voice
     *
     * @return array{success: bool, pin_id: string|null}
     */
    public function sendVoiceToken(
        string $phone,
        int $pinLength      = 6,
        int $pinAttempts    = 3,
        int $pinTtlMinutes  = 5
    ): array {
        try {
            $response = Http::post("{$this->baseUrl}/sms/otp/send/voice", [
                'api_key'           => $this->apiKey,
                'phone_number'      => $this->formatPhone($phone),
                'pin_length'        => $pinLength,
                'pin_attempts'      => $pinAttempts,
                'pin_time_to_live'  => $pinTtlMinutes,
            ]);

            $body = $response->json();

            if (($body['code'] ?? '') === 'ok') {
                Log::info('Termii voice token sent', [
                    'phone_last4' => substr($phone, -4),
                    'message_id'  => $body['message_id'] ?? null,
                    'pin_id'      => $body['pinId'] ?? null,
                ]);

                return [
                    'success' => true,
                    'pin_id'  => $body['pinId'] ?? null,
                ];
            }

            Log::error('Termii voice token failed', [
                'phone_last4' => substr($phone, -4),
                'response'    => $body,
            ]);

            return ['success' => false, 'pin_id' => null];

        } catch (\Exception $e) {
            Log::error('Termii voice token exception', [
                'phone_last4' => substr($phone, -4),
                'error'       => $e->getMessage(),
            ]);

            return ['success' => false, 'pin_id' => null];
        }
    }

    /**
     * Format phone to E.164 Nigeria format (no + prefix).
     * e.g. 08012345678 → 2348012345678
     */
    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '234' . substr($digits, 1);
        }

        if (str_starts_with($digits, '234')) {
            return $digits;
        }

        return $digits;
    }

    /**
     * Verify a voice OTP PIN against Termii's API.
     * POST /api/sms/otp/verify
     *
     * @param string $pinId   The pin_id returned from sendVoiceToken()
     * @param string $pin     The code the user entered
     */
    public function verifyToken(string $pinId, string $pin): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/sms/otp/verify", [
                'api_key' => $this->apiKey,
                'pin_id'  => $pinId,
                'pin'     => $pin,
            ]);

            $body = $response->json();

            $verified = ($body['verified'] ?? false) === true
                     || ($body['verified'] ?? '') === 'True';

            Log::info('Termii token verification', [
                'pin_id'   => $pinId,
                'verified' => $verified,
                'response' => $body,
            ]);

            return $verified;

        } catch (\Exception $e) {
            Log::error('Termii verify token exception', [
                'pin_id' => $pinId,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }
}