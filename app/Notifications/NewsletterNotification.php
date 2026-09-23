<?php

namespace App\Notifications;

use App\Channels\BrevoChannel;
use App\Models\Newsletter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewsletterNotification extends Notification
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(private readonly Newsletter $newsletter) {}

    public function via(object $notifiable): array
    {
        return [BrevoChannel::class];
    }

     public function toBrevo(object $notifiable): array
    {
        return [
            'to'          => [['email' => $notifiable->email, 'name' => $notifiable->first_name ?? $notifiable->email]],
            'subject'     => $this->newsletter->subject,
            'htmlContent' => view('emails.newsletter', [
                'title'       => $this->newsletter->subject,
                'firstName'   => $notifiable->first_name,
                'content'     => $this->newsletter->content,
                'button_url'  => null,
                'button_text' => null,
            ])->render(),
        ];
    }
    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'newsletter',
            'message' => $this->newsletter->subject,
        ];
    }
}