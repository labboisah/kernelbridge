<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SUPER_ADMIN_PASSWORD');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('SUPER_ADMIN_PASSWORD must be set before running the super administrator seeder.');
        }

        DB::transaction(function () use ($password): void {
            $user = User::query()->updateOrCreate(
                ['email' => env('SUPER_ADMIN_EMAIL', 'admin@kernelbridge.com')],
                ['name' => env('SUPER_ADMIN_NAME', 'KernelBridge Super Administrator'), 'password' => Hash::make($password), 'email_verified_at' => now()],
            );

            $user->syncRoles([UserRole::SuperAdmin->value]);
        });
    }
}
