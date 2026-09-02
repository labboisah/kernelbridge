<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

final class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('lead.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can('lead.view');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can('lead.update');
    }

    public function addNote(User $user, Lead $lead): bool
    {
        return $user->can('lead.note');
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $user->can('lead.convert');
    }
}
