<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('⚠️ Withdrawal Failed — PayYigi')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Unfortunately, your withdrawal could not be processed.")
            ->line("**Amount:** ₦" . number_format($this->transaction->amount, 2))
            ->line("**Reference:** {$this->transaction->reference}")
            ->line("**Reason:** " . ($this->transaction->failure_reason ?? 'An unexpected error occurred.'))
            ->line("The funds have been returned to your PayYigi wallet.")
            ->line("Please try again or contact support if the issue persists.")
            ->salutation('The PayYigi Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'withdrawal_failed',
            'message'   => '₦' . number_format($this->transaction->amount, 2) . ' withdrawal failed. Funds returned to your wallet.',
            'reference' => $this->transaction->reference,
        ];
    }
}
