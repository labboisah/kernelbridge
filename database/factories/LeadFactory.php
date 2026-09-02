<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return ['lead_number' => 'KBT-LEAD-'.fake()->unique()->numerify('######'), 'name' => fake()->name(), 'organization' => fake()->company(), 'email' => fake()->unique()->safeEmail(), 'phone' => fake()->phoneNumber(), 'source' => 'Website', 'status' => 'new', 'follow_up_date' => today()->addDays(3)];
    }
}
