<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Basic', 'Professional', 'Enterprise']).' '.fake()->unique()->numberBetween(1, 9999);

        return ['product_id' => Product::factory(), 'name' => $name, 'slug' => str($name)->slug(), 'description' => fake()->sentence(), 'billing_cycle' => 'monthly', 'price' => fake()->randomFloat(2, 10, 5000), 'currency' => 'NGN', 'setup_fee' => 0, 'trial_days' => 14, 'grace_period_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => 0];
    }
}
