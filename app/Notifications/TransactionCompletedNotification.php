<?php

namespace App\Notifications;

use App\Channels\BrevoChannel;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TransactionCompletedNotification extends Notification implements ShouldQueue
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

        $title   = 'Your Sell Order Has Been Completed';
        $message = "Hello {$notifiable->first_name}!\n\n"
            . "Great news! Your sell order has been completed successfully and your payment has been sent to your bank account.\n\n"
            . "Transaction Details:\n"
            . "• Reference: {$txn->reference}\n"
            . "• Asset Sold: {$txn->crypto_amount} {$txn->crypto_asset}\n"
            . "• Amount Paid: ₦" . number_format($txn->amount, 2) . "\n"
            . "• Account: {$txn->account_name} — {$txn->account_number} ({$txn->bank_name})\n\n"
            . "NGN has been sent directly to your bank account. Please allow a few minutes for it to reflect depending on your bank.\n\n"
            . "Thank you for using PayYigi!";

        $button_url  = config('app.url') . '/transactions';
        $button_text = 'View Transaction';

        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => '✅ Sell Order Completed — PayYigi',
            'htmlContent' => view('emails.template', compact('title', 'message', 'button_url', 'button_text'))->render(),
        ];
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