<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportAiService;
use App\Services\Support\SupportMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupportChatController extends Controller
{
    public function __construct(
        private readonly SupportAiService $ai,
        private readonly SupportMailer $mailer,
    ) {}

    public function portal()
    {
        return view('support.portal', ['email' => session('support_email')]);
    }

    public function tickets()
    {
        $tickets = SupportTicket::where('email', $this->email())
            ->orderByDesc('last_message_at')
            ->get(['id', 'reference', 'subject', 'status', 'last_message_at']);

        return response()->json(['data' => $tickets]);
    }

    public function store()
    {
        $email = $this->email();
        $user  = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $ticket = SupportTicket::create([
            'user_id'         => $user?->id,
            'email'           => $email,
            'status'          => 'open',
            'last_message_at' => now(),
        ]);

        $greeting = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'role'              => 'ai',
            'body'              => 'Hi ' . ($user?->first_name ?? 'there') . ", I'm Yigi, PayYigi's support assistant. How can I help you today?",
        ]);

        $this->mailer->record($ticket->load('user'), $greeting);

        return response()->json([
            'data' => ['ticket' => $this->ticketJson($ticket), 'messages' => [$this->messageJson($greeting)]],
        ], 201);
    }

    public function show(string $id)
    {
        $ticket = $this->owned($id);

        return response()->json(['data' => [
            'ticket'   => $this->ticketJson($ticket),
            'messages' => $ticket->messages->map(fn ($m) => $this->messageJson($m))->values(),
        ]]);
    }

    public function send(Request $request, string $id)
    {
        $ticket = $this->owned($id);
        $data   = $request->validate(['message' => 'required|string|max:2000']);

        abort_if($ticket->isClosed(), 422, 'This chat is closed. Please start a new one.');

        $ticket->loadMissing('user');

        // 1. Save + mirror the customer's message
        $userMsg = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'role'              => 'user',
            'body'              => trim($data['message']),
        ]);
        if (!$ticket->subject) {
            $ticket->subject = Str::limit(trim($data['message']), 60);
        }
        $this->mailer->record($ticket, $userMsg);

        // 2. Ask the AI
        $result = $this->ai->reply($ticket, $ticket->user);
        $text   = $result['text'];

        // 3. Escalate to the human team (once per ticket)
        if ($result['escalate'] && !$ticket->isEscalated()) {
            $ticket->status            = 'escalated';
            $ticket->escalation_reason = $result['escalate'];
            $ticket->escalated_at      = now();

            $this->mailer->escalate($ticket, $result['escalate'], $this->ai->snapshot($ticket->user));

            $text .= "\n\nI've forwarded this to our support team. They'll reply to {$ticket->email} by email.";
        }

        // 4. Save + mirror the AI reply
        $aiMsg = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'role'              => 'ai',
            'body'              => $text,
        ]);
        $this->mailer->record($ticket, $aiMsg);

        $ticket->last_message_at = now();
        $ticket->save();

        return response()->json(['data' => [
            'ticket'  => $this->ticketJson($ticket),
            'user'    => $this->messageJson($userMsg),
            'ai'      => $this->messageJson($aiMsg),
        ]]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function email(): string
    {
        return strtolower((string) session('support_email'));
    }

    /** Only tickets belonging to the verified email can ever be loaded. */
    private function owned(string $id): SupportTicket
    {
        return SupportTicket::where('id', $id)->where('email', $this->email())->firstOrFail();
    }

    private function ticketJson(SupportTicket $t): array
    {
        return ['id' => $t->id, 'reference' => $t->reference, 'subject' => $t->subject, 'status' => $t->status, 'last_message_at' => $t->last_message_at];
    }

    private function messageJson(SupportMessage $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'body' => $m->body, 'created_at' => $m->created_at];
    }
}
