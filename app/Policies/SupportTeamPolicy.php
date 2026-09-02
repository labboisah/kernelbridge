<?php

namespace App\Policies;

use App\Models\SupportTeam;
use App\Models\User;

final class SupportTeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('support.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('support.manage');
    }

    public function update(User $user, SupportTeam $team): bool
    {
        return $user->can('support.manage');
    }

    public function delete(User $user, SupportTeam $team): bool
    {
        return $user->can('support.manage');
    }
}
