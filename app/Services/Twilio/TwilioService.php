<?php

namespace App\Services\Twilio;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

class TwilioService
{
    private Client $client;
    private string $from;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );
        $this->from = config('services.twilio.from');
    }

    /**
     * Send plain SMS — used for NIN OTP, 2FA OTP etc.
     */
    public function sendSms(string $phone, string $message): bool
    {
        try {
            $this->client->messages->create(
                $this->formatPhone($phone),
                ['from' => $this->from, 'body' => $message]
            );
            return true;
        } catch (TwilioException $e) {
            \Log::error('Twilio SMS send failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+234' . substr($digits, 1);
        }

        if (str_starts_with($digits, '234')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}