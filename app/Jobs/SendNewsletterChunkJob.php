<?php

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\User;
use App\Notifications\NewsletterNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNewsletterChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 180;

    public function __construct(
        private readonly string $newsletterId,
        private readonly array $userIds
    ) {}

    public function handle(): void
    {
        $newsletter = Newsletter::findOrFail($this->newsletterId);
        $users = User::whereIn('id', $this->userIds)->get(['id', 'first_name', 'email']);

        foreach ($users as $user) {
            $recipient = NewsletterRecipient::where('newsletter_id', $newsletter->id)
                ->where('user_id', $user->id)
                ->first();

            try {
                $user->notify(new NewsletterNotification($newsletter));
                $recipient?->update(['status' => 'sent', 'sent_at' => now()]);
                $newsletter->increment('sent_count');
            } catch (Throwable $e) {
                Log::error('Newsletter send failed', [
                    'newsletter_id' => $newsletter->id,
                    'user_id'       => $user->id,
                    'error'         => $e->getMessage(),
                ]);
                $recipient?->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $newsletter->increment('failed_count');
            }
        }

        $newsletter->refresh();
        $stillPending = NewsletterRecipient::where('newsletter_id', $newsletter->id)
            ->where('status', 'pending')->exists();

        if (! $stillPending) {
            $newsletter->update(['status' => 'sent', 'sent_at' => now()]);
        }
    }
}