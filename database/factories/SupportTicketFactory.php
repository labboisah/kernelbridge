<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

final class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    public function definition(): array
    {
        return ['ticket_number' => 'KBT-SUP-'.fake()->unique()->numerify('######'), 'customer_id' => Customer::factory(), 'product_id' => Product::factory(), 'subject' => fake()->sentence(), 'category' => 'technical', 'priority' => 'normal', 'status' => 'open', 'opened_by' => User::factory(), 'opened_at' => now(), 'first_response_due_at' => now()->addHours(8), 'resolution_due_at' => now()->addDays(3)];
    }
}
