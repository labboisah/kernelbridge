<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

final class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['code' => strtoupper(fake()->unique()->bothify('PRD###??')), 'name' => str($name)->title(), 'slug' => str($name)->slug(), 'tagline' => fake()->sentence(), 'short_description' => fake()->sentence(), 'description' => fake()->paragraph(), 'category' => 'Business software', 'industry' => 'Technology', 'icon' => 'platform', 'features' => ['Secure workflows'], 'status' => 'active', 'is_featured' => false, 'sort_order' => 0];
    }
}
