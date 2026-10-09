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
        
        $password = 'pass1234';

        DB::transaction(function () use ($password): void {
            $user = User::query()->updateOrCreate(
                ['email' => env('SUPER_ADMIN_EMAIL', 'admin@kernelbridge.com')],
                ['name' => env('SUPER_ADMIN_NAME', 'Administrator'), 'password' => Hash::make($password), 'email_verified_at' => now()],
            );

            $user->syncRoles([UserRole::SuperAdmin->value]);
        });
    }
}
