<?php

namespace App\Jobs;

use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SendNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(private readonly string $newsletterId) {}

    public function handle(): void
    {
        $newsletter = Newsletter::findOrFail($this->newsletterId);
        $userIds = Newsletter::audienceQuery($newsletter->audience)->pluck('id');

        if ($userIds->isEmpty()) {
            $newsletter->update(['status' => 'sent', 'total_recipients' => 0, 'sent_at' => now()]);
            return;
        }

        $rows = $userIds->map(fn ($id) => [
            'id'            => (string) Str::uuid(),
            'newsletter_id' => $newsletter->id,
            'user_id'       => $id,
            'status'        => 'pending',
            'created_at'    => now(),
            'updated_at'    => now(),
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            NewsletterRecipient::insert($chunk);
        }

        $newsletter->update(['status' => 'sending', 'total_recipients' => $userIds->count()]);

        foreach ($userIds->chunk(50) as $chunk) {
            SendNewsletterChunkJob::dispatch($newsletter->id, $chunk->all())->onQueue('newsletters');
        }
    }
}