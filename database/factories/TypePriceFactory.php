<?php

namespace Database\Factories;

use App\Models\TypePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TypePrice>
 */
class TypePriceFactory extends Factory
{
    protected $model = TypePrice::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
        ];
    }
}
