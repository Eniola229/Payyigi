<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('⚠️ Buy Order Failed — PayYigi')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Your buy order could not be completed.")
            ->line("**Amount:** ₦" . number_format($this->transaction->amount, 2))
            ->line("**Reference:** {$this->transaction->reference}")
            ->line("**Reason:** " . ($this->transaction->failure_reason ?? 'An unexpected error occurred.'))
            ->line("The funds have been returned to your PayYigi wallet.")
            ->salutation('The PayYigi Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'buy_failed',
            'message'   => 'Buy order failed. ₦' . number_format($this->transaction->amount, 2) . ' returned to wallet.',
            'reference' => $this->transaction->reference,
        ];
    }
}
