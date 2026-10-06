<?php

namespace App\Services\Support;

use App\Jobs\SendSupportEmail;
use App\Models\SupportMessage;
use App\Models\SupportTicket;

class SupportMailer
{
    /** Magic link email — uses the same emails.template as the rest of PayYigi. */
    public function sendAccessLink(string $email, string $url, ?string $name = null): void
    {
        $title   = 'Open PayYigi Support';
        $message = 'Hello' . ($name ? " {$name}" : '') . "!\n\n"
            . "Click the button below to verify your email and open your PayYigi support chats.\n\n"
            . "This link expires in 20 minutes. If you did not request it, you can safely ignore this email.";

        $html = view('emails.template', [
            'title' => $title, 'message' => $message,
            'button_url' => $url, 'button_text' => 'Open Support',
        ])->render();

        $this->dispatch([
            'to' => $email, 'to_name' => $name ?: $email,
            'subject' => 'Your PayYigi Support link', 'html' => $html,
        ]);
    }

    /** Every chat message is mirrored to the records inbox, like a normal email conversation. */
    public function record(SupportTicket $ticket, SupportMessage $message): void
    {
        $customer = $ticket->user?->full_name ?? $ticket->email;
        $isUser   = $message->role === 'user';
        $label    = match ($message->role) { 'user' => 'Customer', 'ai' => 'PayYigi AI', default => 'System' };

        $html = view('emails.support-message', [
            'title'         => $label . ' message',
            'ref'           => $ticket->reference,
            'roleLabel'     => $label,
            'customer'      => $customer,
            'customerEmail' => $ticket->email,
            'body'          => $message->body,
            'time'          => $message->created_at->format('D, d M Y H:i:s') . ' (' . config('app.timezone') . ')',
        ])->render();

        $this->dispatch([
            'to'          => config('services.support.records_email'),
            'subject'     => $this->threadSubject($ticket),
            'html'        => $html,
            'sender_name' => $isUser ? "{$customer} (Customer)" : ($message->role === 'ai' ? 'PayYigi AI' : 'PayYigi System'),
            'reply_to'    => $isUser ? ['email' => $ticket->email, 'name' => $customer] : null,
            'headers'     => ['X-Ticket-Ref' => $ticket->reference],
        ]);
    }

    /** Sent to support@ with Reply-To = the customer, so staff just hit Reply. */
    public function escalate(SupportTicket $ticket, string $reason, string $snapshot): void
    {
        $customer = $ticket->user?->full_name ?? $ticket->email;

        // Newest 30 messages, put back in chronological order.
        $transcript = $ticket->messages()->reorder('created_at', 'desc')->limit(30)->get()->reverse()->map(fn ($m) => [
            'role' => $m->role, 'body' => $m->body, 'time' => $m->created_at->format('d M H:i'),
        ])->values()->all();

        $html = view('emails.support-message', [
            'title'         => 'Escalated to support team',
            'ref'           => $ticket->reference,
            'roleLabel'     => 'Escalation',
            'customer'      => $customer,
            'customerEmail' => $ticket->email,
            'body'          => "The AI assistant escalated this chat.\n\nReason: {$reason}\n\nReply to this email to respond directly to the customer.",
            'time'          => now()->format('D, d M Y H:i:s') . ' (' . config('app.timezone') . ')',
            'snapshot'      => $snapshot,
            'transcript'    => $transcript,
        ])->render();

        $this->dispatch([
            'to'          => config('services.support.email'),
            'subject'     => '[ESCALATED] [' . $ticket->reference . '] ' . mb_strimwidth($reason, 0, 80, '…'),
            'html'        => $html,
            'sender_name' => 'PayYigi AI Support',
            'reply_to'    => ['email' => $ticket->email, 'name' => $customer],
            'headers'     => ['X-Ticket-Ref' => $ticket->reference],
        ]);
    }

    /** Same subject for every message of a ticket => mail clients group them into one thread. */
    private function threadSubject(SupportTicket $ticket): string
    {
        return '[' . $ticket->reference . '] Support chat — ' . ($ticket->user?->full_name ?? $ticket->email);
    }

    private function dispatch(array $payload): void
    {
        SendSupportEmail::dispatchAfterResponse($payload);
    }
}