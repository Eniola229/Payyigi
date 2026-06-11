<?php
namespace App\Notifications;

use App\Channels\BrevoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries     = 3;
    public int $timeout   = 30;
    public int $uniqueFor = 3600;

    public function via(object $notifiable): array
    {
        return [BrevoChannel::class];
    }

    public function toBrevo(object $notifiable): array
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        $title   = 'Verify Your PayYigi Email Address';
        $message = "Hello {$notifiable->first_name}!\n\n"
            . "Welcome to PayYigi! Please verify your email address to get started.\n\n"
            . "This link expires in 60 minutes.\n\n"
            . "If you did not create an account, no further action is required.";

        $button_url  = $verificationUrl;
        $button_text = 'Verify Email Address';

        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => '✅ Verify Your PayYigi Email Address',
            'htmlContent' => view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render(),
        ];
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