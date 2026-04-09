<?php

namespace App\Services\Twilio;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

class TwilioService
{
    private Client $client;
    private string $verifySid;
    private string $from;

    public function __construct() 
    {
        $this->client    = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );
        $this->verifySid = config('services.twilio.verify_sid');
        $this->from      = config('services.twilio.from');
    }

    /**
     * Send OTP via Twilio Verify Service (recommended — handles delivery + verification)
     */
    public function sendVerificationOtp(string $phone): bool
    {
        try {
            $this->client->verify->v2
                ->services($this->verifySid)
                ->verifications
                ->create($this->formatPhone($phone), 'sms');

            return true;
        } catch (TwilioException $e) {
            \Log::error('Twilio OTP send failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verify OTP via Twilio Verify Service
     */
    public function verifyOtp(string $phone, string $code): bool
    {
        try {
            $check = $this->client->verify->v2
                ->services($this->verifySid)
                ->verificationChecks
                ->create([
                    'to'   => $this->formatPhone($phone),
                    'code' => $code,
                ]);

            return $check->status === 'approved';
        } catch (TwilioException $e) {
            \Log::error('Twilio OTP verify failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send plain SMS (for custom OTPs not using Verify Service)
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

    /**
     * Ensure phone is in E.164 format for Nigeria
     */
    private function formatPhone(string $phone): string
    {
        // Strip non-digits
        $digits = preg_replace('/\D/', '', $phone);

        // Nigerian numbers: 0XXXXXXXXXX → +234XXXXXXXXXX
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+234' . substr($digits, 1);
        }

        // Already has country code
        if (str_starts_with($digits, '234')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}