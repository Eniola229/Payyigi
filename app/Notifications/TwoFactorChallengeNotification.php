<?php

namespace App\Notifications;

use App\Channels\BrevoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TwoFactorChallengeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return [BrevoChannel::class];
    }

    public function toBrevo(object $notifiable): array
    {
        $title   = 'Your PayYigi Login Verification Code';
        $message = "Hello {$notifiable->first_name}!\n\n"
            . "A login attempt was made on your account from a new device or location.\n\n"
            . "Your verification code is:\n\n"
            . "🔐 {$this->code}\n\n"
            . "This code expires in 10 minutes. Do not share it with anyone — PayYigi staff will never ask for your code.\n\n"
            . "If you did not attempt to log in, please change your password immediately and contact our support team.";

        $button_url  = config('app.url') . '/security';
        $button_text = 'Secure My Account';

        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => '🔐 Your PayYigi Login Verification Code',
            'htmlContent' => view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render(),
        ];
    }
}