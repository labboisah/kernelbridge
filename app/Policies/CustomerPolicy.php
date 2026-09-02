<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customer.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customer.view');
    }

    public function create(User $user): bool
    {
        return $user->can('customer.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customer.update');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customer.delete');
    }

    public function addNote(User $user, Customer $customer): bool
    {
        return $user->can('customer.note');
    }
}
