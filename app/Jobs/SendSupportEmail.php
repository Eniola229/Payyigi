<?php

namespace App\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one email through Brevo. Dispatched with dispatchAfterResponse()
 * so the chat UI never waits on email delivery (and no queue worker is required).
 */
class SendSupportEmail
{
    use Dispatchable;

    public function __construct(private readonly array $payload) {}

    public function handle(): void
    {
        $p = $this->payload;

        $body = [
            'sender'      => ['email' => config('mail.from.address'), 'name' => $p['sender_name'] ?? config('mail.from.name')],
            'to'          => [['email' => $p['to'], 'name' => $p['to_name'] ?? $p['to']]],
            'subject'     => $p['subject'],
            'htmlContent' => $p['html'],
        ];

        if (!empty($p['reply_to'])) {
            $body['replyTo'] = $p['reply_to'];   // ['email' => ..., 'name' => ...]
        }
        if (!empty($p['headers'])) {
            $body['headers'] = $p['headers'];
        }

        try {
            Http::withHeaders(['api-key' => config('services.brevo.key')])
                ->acceptJson()
                ->timeout(20)
                ->retry(2, 1000, throw: false)
                ->post('https://api.brevo.com/v3/smtp/email', $body)
                ->throw();
        } catch (\Throwable $e) {
            Log::error('SendSupportEmail failed', ['to' => $p['to'], 'subject' => $p['subject'], 'error' => $e->getMessage()]);
        }
    }
}
