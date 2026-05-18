<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransactionCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('✅ Sell Order Completed — PayYigi')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Your sell order has been completed successfully.")
            ->line("**Amount:** ₦" . number_format($this->transaction->amount, 2))
            ->line("**Asset Sold:** {$this->transaction->crypto_amount} {$this->transaction->crypto_asset}")
            ->line("**Reference:** {$this->transaction->reference}")
            ->line("**Paid to:** {$this->transaction->account_name} — {$this->transaction->account_number} ({$this->transaction->bank_name})")
            ->line('NGN has been sent directly to your bank account.')
            ->salutation('The PayYigi Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'sell_completed',
            'message'   => '₦' . number_format($this->transaction->amount, 2) . ' sent to your bank account ' . $this->transaction->account_number,
            'reference' => $this->transaction->reference,
            'amount'    => $this->transaction->amount,
        ];
    }
}
