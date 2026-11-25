<?php

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('staff.manage');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->is($subject) || $user->can('staff.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('staff.manage');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->can('staff.manage');
    }
}
