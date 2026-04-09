<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorChallengeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        // Send both email and SMS for maximum delivery reliability
        return ['mail'];
        // Add 'vonage' or your SMS driver here once configured
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PayYigi Login Verification Code')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line('A login attempt was made from a new device.')
            ->line("Your verification code is: **{$this->code}**")
            ->line('This code expires in 10 minutes.')
            ->line('If you did not attempt to log in, please change your password immediately.')
            ->salutation('The PayYigi Security Team');
    }
}
