<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Newsletter;
use App\Models\NewsletterRecipient;
use App\Models\User;
use App\Notifications\NewsletterNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

class NewsletterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $newsletters = Newsletter::with('creator:id,first_name,last_name')
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $newsletters]);
    }

    public function show(Newsletter $newsletter): JsonResponse
    {
        $newsletter->loadCount([
            'recipients as pending_count' => fn ($q) => $q->where('status', 'pending'),
            'recipients as sent_recipients_count' => fn ($q) => $q->where('status', 'sent'),
            'recipients as failed_recipients_count' => fn ($q) => $q->where('status', 'failed'),
        ]);

        return response()->json(['data' => $newsletter]);
    }

    /**
     * Create the newsletter and snapshot its recipient list.
     * No sending happens here — that's driven by processBatch().
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'subject'  => 'required|string|max:255',
            'content'  => 'required|string',
            'audience' => 'required|string|in:' . implode(',', Newsletter::AUDIENCES),
        ]);

        $newsletter = Newsletter::create([
            'subject'    => $request->subject,
            'content'    => clean($request->content),
            'audience'   => $request->audience,
            'status'     => 'queued',
            'created_by' => $request->user('admin')->id,
        ]);

        $userIds = Newsletter::audienceQuery($newsletter->audience)->pluck('id');

        if ($userIds->isNotEmpty()) {
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
        }

        $newsletter->update(['total_recipients' => $userIds->count()]);

        AuditLog::create([
            'user_id'        => $request->user('admin')->id,
            'event'          => 'admin.newsletter_created',
            'auditable_type' => Newsletter::class,
            'auditable_id'   => $newsletter->id,
            'new_values'     => ['audience' => $newsletter->audience, 'subject' => $newsletter->subject],
            'ip_address'     => $request->ip(),
        ]);

        return response()->json(['message' => 'Newsletter created.', 'data' => $newsletter], 201);
    }

    /**
     * Process the next batch of pending recipients synchronously.
     * The frontend calls this repeatedly until `done` is true.
     */
    public function processBatch(Request $request, Newsletter $newsletter): JsonResponse
    {
        if (in_array($newsletter->status, ['sent'])) {
            return response()->json([
                'done'             => true,
                'sent_count'       => $newsletter->sent_count,
                'failed_count'     => $newsletter->failed_count,
                'total_recipients' => $newsletter->total_recipients,
            ]);
        }

        $batchSize = min((int) $request->input('batch_size', 15), 30); // keep each call fast

        $newsletter->update(['status' => 'sending']);

        $recipients = NewsletterRecipient::where('newsletter_id', $newsletter->id)
            ->where('status', 'pending')
            ->limit($batchSize)
            ->get();

        if ($recipients->isEmpty()) {
            $newsletter->update(['status' => 'sent', 'sent_at' => now()]);

            return response()->json([
                'done'             => true,
                'sent_count'       => $newsletter->sent_count,
                'failed_count'     => $newsletter->failed_count,
                'total_recipients' => $newsletter->total_recipients,
            ]);
        }

        $users = User::whereIn('id', $recipients->pluck('user_id'))
            ->get(['id', 'first_name', 'email'])
            ->keyBy('id');

        foreach ($recipients as $recipient) {
            $user = $users->get($recipient->user_id);

            if (! $user) {
                $recipient->update(['status' => 'failed', 'error' => 'User not found']);
                $newsletter->increment('failed_count');
                continue;
            }

            try {
                $user->notify(new NewsletterNotification($newsletter));
                $recipient->update(['status' => 'sent', 'sent_at' => now()]);
                $newsletter->increment('sent_count');
            } catch (Throwable $e) {
                Log::error('Newsletter send failed', [
                    'newsletter_id' => $newsletter->id,
                    'user_id'       => $user->id,
                    'error'         => $e->getMessage(),
                ]);
                $recipient->update(['status' => 'failed', 'error' => $e->getMessage()]);
                $newsletter->increment('failed_count');
            }
        }

        $stillPending = NewsletterRecipient::where('newsletter_id', $newsletter->id)
            ->where('status', 'pending')
            ->exists();

        $newsletter->refresh();

        if (! $stillPending) {
            $newsletter->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return response()->json([
            'done'             => ! $stillPending,
            'sent_count'       => $newsletter->sent_count,
            'failed_count'     => $newsletter->failed_count,
            'total_recipients' => $newsletter->total_recipients,
        ]);
    }
}