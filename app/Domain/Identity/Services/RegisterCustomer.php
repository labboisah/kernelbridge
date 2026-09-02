<?php

namespace App\Domain\Identity\Services;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterCustomer
{
    /** @param array{name: string, email: string, password: string, account_type: string, organization?: string|null, phone?: string|null} $attributes */
    public function execute(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);
            $user->assignRole(UserRole::Customer->value);
            $customer = Customer::query()->create([
                'type' => $attributes['account_type'],
                'name' => $attributes['account_type'] === 'organization' ? $attributes['organization'] : $user->name,
                'email' => $user->email,
                'phone' => $attributes['phone'] ?? null,
                'status' => 'prospect',
                'source' => 'self_registration',
            ]);
            $customer->forceFill(['customer_number' => sprintf('KBT-CUS-%06d', $customer->id)])->save();
            $customer->users()->attach($user->id, ['is_primary' => true]);

            return $user;
        });
    }
}
