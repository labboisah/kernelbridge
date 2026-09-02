<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return ['customer_number' => 'KBT-CUS-'.fake()->unique()->numerify('######'), 'type' => 'organization', 'name' => fake()->company(), 'email' => fake()->unique()->companyEmail(), 'phone' => fake()->phoneNumber(), 'country' => 'NG', 'status' => 'active', 'source' => 'factory'];
    }
}
