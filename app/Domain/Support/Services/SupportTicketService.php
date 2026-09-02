<?php

namespace App\Domain\Support\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Security\Contracts\FileScanner;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SupportMessage;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketReply;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final readonly class SupportTicketService
{
    public function __construct(private AuditRecorder $audit, private FileScanner $scanner) {}

    public function create(Customer $customer, array $data, User $actor): SupportTicket
    {
        $ticket = DB::transaction(function () use ($customer, $data, $actor) {
            $product = ! empty($data['product_uuid']) ? Product::query()->where('uuid', $data['product_uuid'])->firstOrFail() : null;
            $team = SupportTeam::query()->when(! empty($data['team_uuid']), fn ($query) => $query->where('uuid', $data['team_uuid']), fn ($query) => $query->where('slug', $data['category']))->where('is_active', true)->firstOrFail();
            $hasProduct = $product && ($customer->products()->whereKey($product->id)->exists()
                || $customer->subscriptions()->where('product_id', $product->id)->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->exists());
            if ($product && ! $hasProduct) {
                throw ValidationException::withMessages(['product_uuid' => 'This product is not linked to your customer account.']);
            }
            [$response,$resolution] = $this->deadlines(SupportPriority::from($data['priority']));
            $ticket = SupportTicket::query()->create(['customer_id' => $customer->id, 'product_id' => $product?->id, 'support_team_id' => $team->id, 'subject' => $data['subject'], 'category' => $team->slug, 'priority' => $data['priority'], 'status' => SupportStatus::Open, 'opened_by' => $actor->id, 'opened_at' => now(), 'first_response_due_at' => $response, 'resolution_due_at' => $resolution, 'last_customer_reply_at' => now()]);
            $ticket->forceFill(['ticket_number' => sprintf('KBT-SUP-%06d', $ticket->id)])->save();
            $message = $ticket->messages()->create(['sender_id' => $actor->id, 'type' => 'reply', 'body' => $data['message'], 'is_customer_visible' => true]);
            $this->attachments($message, $data['attachments'] ?? [], $actor);
            $this->audit->record('support.ticket_opened', $actor, ['ticket_uuid' => $ticket->uuid, 'customer_uuid' => $customer->uuid]);

            return $ticket;
        });
        $this->notifyStaff($ticket->messages()->first());

        return $ticket;
    }

    public function message(SupportTicket $ticket, string $body, array $files, User $actor, bool $internal = false): SupportMessage
    {
        $staff = $actor->can('support.reply');
        if ($ticket->status === SupportStatus::Closed) {
            throw ValidationException::withMessages(['status' => 'Closed tickets must be reopened before replying.']);
        }$message = DB::transaction(function () use ($ticket, $body, $files, $actor, $internal, $staff) {
            $message = $ticket->messages()->create(['sender_id' => $actor->id, 'type' => $internal ? 'internal_note' : 'reply', 'body' => $body, 'is_customer_visible' => ! $internal]);
            $this->attachments($message, $files, $actor);
            if ($staff) {
                $updates = ['last_staff_reply_at' => now()];
                if (! $ticket->first_responded_at) {
                    $updates['first_responded_at'] = now();
                    if (now()->gt($ticket->first_response_due_at)) {
                        $updates['sla_breached_at'] = now();
                    }
                }if (! $internal && in_array($ticket->status, [SupportStatus::Open, SupportStatus::WaitingCustomer], true)) {
                    $updates['status'] = SupportStatus::InProgress;
                }$ticket->update($updates);
            } else {
                $ticket->update(['last_customer_reply_at' => now(), 'status' => SupportStatus::Open]);
            }$this->audit->record($internal ? 'support.internal_note_added' : 'support.reply_added', $actor, ['ticket_uuid' => $ticket->uuid, 'message_uuid' => $message->uuid]);

            return $message;
        });
        if (! $internal) {
            $staff ? $this->notifyCustomer($message) : $this->notifyStaff($message);
        }

        return $message;
    }

    public function update(SupportTicket $ticket, array $data, User $actor): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $before = $ticket->only(['assigned_to', 'priority', 'status']);
            if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null && ! User::query()->findOrFail($data['assigned_to'])->can('support.manage')) {
                throw ValidationException::withMessages(['assigned_to' => 'Tickets can only be assigned to an authorized support agent.']);
            }
            if (isset($data['priority']) && $ticket->first_responded_at === null) {
                [$data['first_response_due_at'], $data['resolution_due_at']] = $this->deadlinesFrom(SupportPriority::from($data['priority']), $ticket->opened_at);
            }
            if (isset($data['status'])) {
                $status = SupportStatus::from($data['status']);
                if ($status === SupportStatus::Closed) {
                    $data['closed_at'] = now();
                } elseif ($ticket->status === SupportStatus::Closed) {
                    $data['closed_at'] = null;
                }if ($status === SupportStatus::Resolved) {
                    $data['resolved_at'] = now();
                    if ($ticket->resolution_due_at && now()->gt($ticket->resolution_due_at)) {
                        $data['sla_breached_at'] ??= now();
                    }
                } elseif ($ticket->status === SupportStatus::Resolved) {
                    $data['resolved_at'] = null;
                }
            }$ticket->update($data);
            $this->audit->record('support.ticket_updated', $actor, ['ticket_uuid' => $ticket->uuid, 'before' => $before, 'after' => $ticket->only(['assigned_to', 'priority', 'status'])]);

            return $ticket->refresh();
        });
    }

    public function markSlaBreaches(): int
    {
        return SupportTicket::query()->whereNull('sla_breached_at')->whereNotIn('status', ['resolved', 'closed'])->where(function ($query) {
            $query->where(fn ($q) => $q->whereNull('first_responded_at')->where('first_response_due_at', '<', now()))->orWhere('resolution_due_at', '<', now());
        })->update(['sla_breached_at' => now(), 'updated_at' => now()]);
    }

    private function deadlines(SupportPriority $priority): array
    {
        return $this->deadlinesFrom($priority, now());
    }

    private function deadlinesFrom(SupportPriority $priority, $start): array
    {
        [$response,$resolution] = match ($priority) {
            SupportPriority::Urgent => [1, 8],SupportPriority::High => [4, 24],SupportPriority::Normal => [8, 72],SupportPriority::Low => [24, 120]
        };

        return [$start->copy()->addHours($response), $start->copy()->addHours($resolution)];
    }

    private function attachments(SupportMessage $message, array $files, User $actor): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $this->scanner->assertClean($file);
            $path = $file->store('support-attachments', 'local');
            $message->attachments()->create(['uploaded_by' => $actor->id, 'disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize()]);
        }
    }

    private function notifyCustomer(SupportMessage $message): void
    {
        $message->load('ticket.customer');
        if ($message->ticket->customer->email) {
            Notification::route('mail', $message->ticket->customer->email)->notify(new SupportTicketReply($message, true));
        }
    }

    private function notifyStaff(SupportMessage $message): void
    {
        $message->load('ticket');
        $users = $message->ticket->assigned_to ? User::query()->whereKey($message->ticket->assigned_to)->get() : User::role('support')->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new SupportTicketReply($message, false));
        }
    }
}
