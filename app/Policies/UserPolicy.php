<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class UserPolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole(UserRole::SuperAdmin->value) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('staff.manage');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->is($subject) || $user->hasRole(UserRole::Admin->value);
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
