<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransactionFailedNotification extends Notification implements ShouldQueue
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
            ->subject("❌ {$type} Failed — PayYigi")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Unfortunately, your {$t->type} transaction could not be completed.")
            ->line("**Reference:** {$t->reference}")
            ->line("**Amount:** ₦" . number_format($t->amount, 2))
            ->when($t->failure_reason, fn($m) => $m->line("**Reason:** {$t->failure_reason}"))
            ->when($t->type === 'withdraw', fn($m) => $m
                ->line('Your wallet has been refunded automatically.')
            )
            ->line('If you have any questions, please contact our support team.')
            ->salutation('The PayYigi Team');
    }
}
