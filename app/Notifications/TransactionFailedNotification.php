<?php

namespace App\Notifications;

use App\Channels\BrevoChannel;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TransactionFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(private readonly Transaction $transaction) {}

    public function via(object $notifiable): array
    {
        return [BrevoChannel::class];
    }

    public function toBrevo(object $notifiable): array
    {
        $txn  = $this->transaction;
        $type = ucfirst($txn->type);

        $title   = "Your {$type} Transaction Has Failed";
        $message = "Hello {$notifiable->first_name},\n\n"
            . "Unfortunately, your {$txn->type} transaction could not be completed.\n\n"
            . "Transaction Details:\n"
            . "• Reference: {$txn->reference}\n"
            . "• Amount: ₦" . number_format($txn->amount, 2) . "\n"
            . ($txn->failure_reason ? "• Reason: {$txn->failure_reason}\n" : '')
            . "\n"
            . ($txn->type === 'withdraw'
                ? "Your wallet balance has been refunded automatically. The funds should already be available in your account.\n\n"
                : '')
            . "If you believe this is an error or need further assistance, please don't hesitate to reach out to our support team with your transaction reference.\n\n"
            . "We apologise for any inconvenience caused.";

        $button_url  = config('app.url') . '/support';
        $button_text = 'Contact Support';

        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => "❌ {$type} Failed — PayYigi",
            'htmlContent' => view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render(),
        ];
    }
}