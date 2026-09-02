<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            ProductSeeder::class,
            DemoProfileSeeder::class,
            PlanSeeder::class,
            SupportTeamSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
