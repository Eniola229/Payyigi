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
        $t    = $this->transaction;
        $type = ucfirst($t->type);

        return (new MailMessage)
            ->subject("✅ {$type} Successful — PayYigi")
            ->greeting("Hello {$notifiable->first_name}!")
            ->line("Your {$t->type} transaction has been completed successfully.")
            ->line("**Amount:** ₦" . number_format($t->net_amount, 2))
            ->line("**Reference:** {$t->reference}")
            ->when($t->type === 'sell', fn($m) => $m
                ->line("**Crypto:** {$t->crypto_amount} {$t->crypto_asset}")
                ->line("**Rate:** ₦" . number_format($t->rate, 2) . " per {$t->crypto_asset}")
            )
            ->when($t->type === 'withdraw', fn($m) => $m
                ->line("**Bank:** {$t->bank_name}")
                ->line("**Account:** {$t->account_number}")
            )
            ->line('Thank you for using PayYigi!')
            ->salutation('The PayYigi Team');
    }
}
