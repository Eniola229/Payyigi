<?php
namespace App\Notifications;

use App\Channels\BrevoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries     = 3;
    public int $timeout   = 30;
    public int $uniqueFor = 3600;

    public function __construct(protected string $token) {}

    public function via(object $notifiable): array
    {
        return [BrevoChannel::class];
    }

    public function toBrevo(object $notifiable): array
    {
        $title   = 'Reset Your PayYigi Password';
        $message = "Hello {$notifiable->first_name}!\n\n"
            . "We received a request to reset your PayYigi password.\n\n"
            . "Click the button below to choose a new password. This link expires in 60 minutes.\n\n"
            . "If you did not request a password reset, no further action is required.";

        $button_url  = $this->resetUrl($notifiable);
        $button_text = 'Reset Password';

        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => '🔑 Reset Your PayYigi Password',
            'htmlContent' => view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render(),
        ];
    }

    protected function resetUrl(object $notifiable): string
    {
        return rtrim(config('app.frontend_url'), '/')
            . '/reset-password/'
            . $this->token
            . '/'
            . urlencode($notifiable->email);
    }
}