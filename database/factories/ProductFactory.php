<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'code' => fake()->unique()->bothify('PROD-####'),
            'content_type' => 'Unidad',
            'content' => '1',
            'cost' => fake()->randomFloat(2, 1, 100),
            'is_active' => true,
            'category_id' => CategoryFactory::new(),
        ];
    }
}
