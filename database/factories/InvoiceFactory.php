<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $total = fake()->randomFloat(2, 1000, 1000000);

        return ['invoice_number' => 'KBT-INV-'.now()->year.'-'.fake()->unique()->numerify('######'), 'customer_id' => Customer::factory(), 'issue_date' => today(), 'due_date' => today()->addDays(30), 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total, 'amount_paid' => 0, 'balance' => $total, 'currency' => 'NGN', 'status' => 'issued', 'created_by' => User::factory(), 'issued_at' => now()];
    }
}
