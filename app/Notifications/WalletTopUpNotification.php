<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WalletTopUpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array { return ['mail', 'database']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('💰 Wallet Topped Up — PayYigi')
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Your PayYigi wallet has been credited.")
            ->line("**Amount Received:** ₦" . number_format($this->transaction->amount, 2))
            ->line("**Fee Deducted:** ₦" . number_format($this->transaction->fee + $this->transaction->provider_fee, 2))
            ->line("**Net Credited:** ₦" . number_format($this->transaction->net_amount, 2))
            ->line("**Reference:** {$this->transaction->provider_reference}")
            ->line('Your wallet balance has been updated.')
            ->salutation('The PayYigi Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'      => 'wallet_topup',
            'message'   => '₦' . number_format($this->transaction->net_amount, 2) . ' credited to your wallet.',
            'reference' => $this->transaction->provider_reference,
            'amount'    => $this->transaction->net_amount,
        ];
    }
}