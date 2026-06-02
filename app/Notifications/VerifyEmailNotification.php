<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use GuzzleHttp\Client;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // only try once, no retries
    public int $uniqueFor = 3600; // prevent duplicate jobs for 1 hour

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): void
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        $title       = 'Verify Your Email Address';
        $message     = "Hello {$notifiable->first_name}!\n\nWelcome to PayYigi! Please verify your email address to get started.\n\nThis link expires in 60 minutes.\n\nIf you did not create an account, no further action is required.";
        $button_url  = $verificationUrl;
        $button_text = 'Verify Email Address';

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
                    ['email' => $notifiable->email, 'name' => $notifiable->first_name]
                ],
                'subject'     => 'Verify Your PayYigi Email Address',
                'htmlContent' => $html,
            ],
        ]);
    }

    protected function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id'   => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}