<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('✅ Withdrawal Successful — PayYigi')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Your withdrawal has been processed successfully.")
            ->line("**Amount:** ₦" . number_format($this->transaction->amount, 2))
            ->line("**Bank:** {$this->transaction->account_name} — {$this->transaction->account_number}")
            ->line("**Reference:** {$this->transaction->reference}")
            ->salutation('The PayYigi Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'withdrawal_completed',
            'message'   => '₦' . number_format($this->transaction->amount, 2) . ' withdrawal sent to your bank account.',
            'reference' => $this->transaction->reference,
            'amount'    => $this->transaction->amount,
        ];
    }
}
