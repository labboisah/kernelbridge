<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SalesNewDemoRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly DemoRequest $demoRequest)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->demoRequest->lead;

        return (new MailMessage)
            ->subject("New demo request: {$lead->organization}")
            ->greeting('A new sales opportunity has arrived')
            ->line("{$lead->name} from {$lead->organization} requested a demo.")
            ->line('Product: '.($this->demoRequest->product?->name ?? 'Not specified'))
            ->line("Lead: {$lead->lead_number}")
            ->action('Open lead', route('admin.leads.show', $lead));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'demo_request_received', 'demo_request_uuid' => $this->request->uuid, 'message' => "New demo request from {$this->request->name}."];
    }
}
