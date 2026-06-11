<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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

    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);

        $title       = 'Reset Your Password';
        $message     = "Hello {$notifiable->first_name}!\n\nWe received a request to reset your PayYigi password.\n\nClick the button below to choose a new password. This link expires in 60 minutes.\n\nIf you did not request a password reset, no further action is required.";
        $button_url  = $resetUrl;
        $button_text = 'Reset Password';

        $html = view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render();

        return (new MailMessage)
            ->subject('Reset Your PayYigi Password')
            ->to($notifiable->email, $notifiable->first_name ?? $notifiable->email)
            ->html($html);
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