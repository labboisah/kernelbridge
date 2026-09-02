<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'plan_id' => Plan::factory(), 'subscription_number' => 'KBT-SUB-'.now()->year.'-'.fake()->unique()->numerify('######'), 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'auto_renew' => true, 'price' => 10000, 'currency' => 'NGN', 'billing_cycle' => 'monthly', 'created_by' => User::factory()];
    }

    public function configure(): static
    {
        return $this->afterMaking(fn (Subscription $subscription) => $subscription->product_id = $subscription->plan->product_id);
    }
}
