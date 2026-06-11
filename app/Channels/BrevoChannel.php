<?php

namespace App\Channels;

use GuzzleHttp\Client;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class BrevoChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toBrevo')) {
            return;
        }

        $data = $notification->toBrevo($notifiable);

        if (empty($data)) {
            return;
        }

        try {
            (new Client())->post('https://api.brevo.com/v3/smtp/email', [
                'headers' => [
                    'api-key'      => config('services.brevo.key'),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'sender' => [
                        'email' => config('mail.from.address'),
                        'name'  => config('mail.from.name'),
                    ],
                    'to'          => $data['to'],
                    'subject'     => $data['subject'],
                    'htmlContent' => $data['htmlContent'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BrevoChannel: failed to send email', [
                'notifiable' => $notifiable->email ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}