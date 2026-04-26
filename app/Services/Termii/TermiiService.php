<?php

namespace App\Services\Termii;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiService
{
    private string $apiKey;
    private string $senderId;
    private string $baseUrl;
    private string $channel;

    public function __construct()
    {
        $this->apiKey   = config('services.termii.api_key');
        $this->senderId = config('services.termii.sender_id');
        $this->baseUrl  = config('services.termii.base_url');
        $this->channel  = config('services.termii.channel', 'dnd');
    }

    /**
     * Send a plain SMS message.
     * Uses DND (transactional) channel — ensures delivery even to DND numbers.
     * This is required for OTPs and security codes.
     *
     * POST /api/sms/send
     */
    public function sendSms(string $phone, string $message): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/sms/send", [
                'api_key'  => $this->apiKey,
                'to'       => $this->formatPhone($phone),
                'from'     => $this->senderId,
                'sms'      => $message,
                'type'     => 'plain',
                'channel'  => $this->channel,
            ]);

            $body = $response->json();

            if (($body['code'] ?? '') === 'ok') {
                Log::info('Termii SMS sent', [
                    'phone_last4' => substr($phone, -4),
                    'message_id'  => $body['message_id'] ?? null,
                ]);
                return true;
            }

            Log::error('Termii SMS failed', [
                'phone_last4' => substr($phone, -4),
                'response'    => $body,
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Termii SMS exception', [
                'phone_last4' => substr($phone, -4),
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Format phone to E.164 Nigeria format.
     * Termii accepts numbers with country code, no + prefix.
     * e.g. 08012345678 → 2348012345678
     */
    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // 0XXXXXXXXXX → 234XXXXXXXXXX
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '234' . substr($digits, 1);
        }

        // Already has 234
        if (str_starts_with($digits, '234')) {
            return $digits;
        }

        return $digits;
    }
}