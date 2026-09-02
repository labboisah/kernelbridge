<?php

namespace Database\Factories;

use App\Models\DemoRequest;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

final class DemoRequestFactory extends Factory
{
    protected $model = DemoRequest::class;

    public function definition(): array
    {
        $company = fake()->company();

        return ['lead_id' => Lead::factory(), 'product_id' => Product::factory(), 'name' => fake()->name(), 'organization' => $company, 'company' => $company, 'email' => fake()->safeEmail(), 'phone' => fake()->phoneNumber(), 'organization_type' => 'company', 'number_of_users' => 25, 'preferred_demo_date' => today()->addWeek(), 'preferred_demo_time' => '10:00', 'message' => fake()->sentence(), 'source' => 'Website', 'status' => 'pending'];
    }
}
