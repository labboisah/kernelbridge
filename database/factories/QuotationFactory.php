<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        $total = fake()->randomFloat(2, 1000, 1000000);

        return ['quotation_number' => 'KBT-QTN-'.now()->year.'-'.fake()->unique()->numerify('######'), 'customer_id' => Customer::factory(), 'issue_date' => today(), 'expiry_date' => today()->addDays(30), 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total, 'currency' => 'NGN', 'status' => 'draft', 'created_by' => User::factory()];
    }
}
