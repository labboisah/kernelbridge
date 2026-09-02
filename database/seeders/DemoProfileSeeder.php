<?php

namespace Database\Seeders;

use App\Domain\Demo\Services\DemoProfileProvisioner;
use Illuminate\Database\Seeder;

final class DemoProfileSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoProfileProvisioner::class)->provisionActiveProducts();
    }
}
