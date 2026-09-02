<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

final class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('plan.view');
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->can('plan.view');
    }

    public function create(User $user): bool
    {
        return $user->can('plan.create');
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->can('plan.update');
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->can('plan.delete');
    }
}
