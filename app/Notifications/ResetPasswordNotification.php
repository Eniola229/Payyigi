<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use GuzzleHttp\Client;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function __construct(protected string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): void
    {
        $resetUrl = $this->resetUrl($notifiable);

        $title       = 'Reset Your Password';
        $message     = "Hello {$notifiable->first_name}!\n\nWe received a request to reset your PayYigi password.\n\nClick the button below to choose a new password. This link expires in 60 minutes.\n\nIf you did not request a password reset, no further action is required.";
        $button_url  = $resetUrl;
        $button_text = 'Reset Password';

        $html = view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render();

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
                'to' => [
                    ['email' => $notifiable->email, 'name' => $notifiable->first_name],
                ],
                'subject'     => 'Reset Your PayYigi Password',
                'htmlContent' => $html,
            ],
        ]);
    }

    protected function resetUrl(object $notifiable): string
    {
        return rtrim(config('app.frontend_url'), '/') . '/reset-password'
            . '?token=' . $this->token
            . '&email=' . urlencode($notifiable->email);
    }
}