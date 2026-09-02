<?php

namespace Database\Factories;

use App\Models\SoftwareLicense;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

final class SoftwareLicenseFactory extends Factory
{
    protected $model = SoftwareLicense::class;

    public function definition(): array
    {
        return ['subscription_id' => Subscription::factory(), 'key_lookup' => hash('sha256', fake()->unique()->uuid()), 'key_hash' => Hash::make('factory-license-key'), 'status' => 'active', 'max_activations' => 1, 'expires_at' => now()->addMonth()];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SoftwareLicense $license): void {
            $license->customer_id = $license->subscription->customer_id;
            $license->product_id = $license->subscription->product_id;
        });
    }
}
