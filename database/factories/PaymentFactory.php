<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return ['payment_number' => 'KBT-PAY-'.now()->year.'-'.fake()->unique()->numerify('######'), 'customer_id' => Customer::factory(), 'amount' => fake()->randomFloat(2, 1000, 100000), 'currency' => 'NGN', 'payment_method' => 'bank_transfer', 'transaction_reference' => fake()->unique()->uuid(), 'payment_date' => today(), 'status' => 'pending', 'provider' => 'manual', 'recorded_by' => User::factory()];
    }
}
