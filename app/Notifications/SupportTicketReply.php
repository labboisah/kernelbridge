<?php

namespace App\Notifications;

use App\Models\SupportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SupportTicketReply extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SupportMessage $message, private readonly bool $forCustomer)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->message->ticket;

        return (new MailMessage)->subject("[{$ticket->ticket_number}] {$ticket->subject}")->line($this->forCustomer ? 'KernelBridge Support replied to your ticket.' : 'A customer replied to a support ticket.')->line(str($this->message->body)->limit(500))->action('View ticket', $this->forCustomer ? route('support.show', $ticket) : route('admin.support.show', $ticket));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->message->ticket->messages()->count() === 1 ? 'support_ticket_created' : 'support_reply_received', 'ticket_uuid' => $this->message->ticket->uuid, 'message_uuid' => $this->message->uuid, 'message' => str($this->message->body)->limit(200)->toString()];
    }
}
