<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

final class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('support.view') || $user->customers()->exists();
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.view')
            || $user->customers()->whereKey($ticket->customer_id)->exists()
            || ($user->isApprovedAgent() && $ticket->customer()->where('partner_user_id', $user->id)->exists());
    }

    public function create(User $user): bool
    {
        return $user->can('support.create') || $user->customers()->exists();
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.reply')
            || ($ticket->status->value !== 'closed' && $user->customers()->whereKey($ticket->customer_id)->exists())
            || ($ticket->status->value !== 'closed' && $user->isApprovedAgent() && $ticket->customer()->where('partner_user_id', $user->id)->exists());
    }

    public function manage(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.manage');
    }

    public function note(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.note');
    }

    public function updateByRequester(User $user, SupportTicket $ticket): bool
    {
        return $this->requesterCanManage($user, $ticket);
    }

    public function resolveByRequester(User $user, SupportTicket $ticket): bool
    {
        return $this->requesterCanManage($user, $ticket);
    }

    private function requesterCanManage(User $user, SupportTicket $ticket): bool
    {
        if (in_array($ticket->status->value, ['resolved', 'closed'], true)) {
            return false;
        }

        return $user->customers()->whereKey($ticket->customer_id)->exists()
            || ($user->isApprovedAgent() && $ticket->customer()->where('partner_user_id', $user->id)->exists());
    }
}
