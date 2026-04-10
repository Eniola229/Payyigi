<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BuyCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('✅ Buy Order Completed — PayYigi')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Your buy order has been completed.")
            ->line("**Asset:** {$this->transaction->crypto_amount} {$this->transaction->crypto_asset}")
            ->line("**NGN Spent:** ₦" . number_format($this->transaction->amount, 2))
            ->line("**Reference:** {$this->transaction->reference}")
            ->line('Crypto has been sent to your wallet address.')
            ->salutation('The PayYigi Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'buy_completed',
            'message'   => "{$this->transaction->crypto_amount} {$this->transaction->crypto_asset} sent to your wallet.",
            'reference' => $this->transaction->reference,
        ];
    }
}
