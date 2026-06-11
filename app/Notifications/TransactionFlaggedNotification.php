<?php

namespace App\Notifications;

use App\Channels\BrevoChannel;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TransactionFlaggedNotification extends Notification implements ShouldQueue
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
        $txn = $this->transaction;

        $title   = 'Your Crypto Deposit Has Been Flagged';
        $message = "Hello {$notifiable->first_name}!\n\n"
            . "Your crypto deposit has been flagged by our processing partner. This usually happens when the amount sent is below the minimum required.\n\n"
            . "Transaction Details:\n"
            . "• Reference: {$txn->reference}\n"
            . "• Asset: {$txn->crypto_asset} ({$txn->crypto_network})\n"
            . "• Amount Sent: {$txn->crypto_amount} {$txn->crypto_asset}\n\n"
            . "What happens next?\n"
            . "Our team has been notified and will review your transaction. If your funds are recoverable, they will be processed manually. "
            . "Please contact our support team with your transaction reference for assistance.\n\n"
            . "We apologise for the inconvenience.";

        $button_url  = config('app.url') . '/support';
        $button_text = 'Contact Support';

        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => 'Action Required: Crypto Deposit Flagged - PayYigi',
            'htmlContent' => view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render(),
        ];
    }
}