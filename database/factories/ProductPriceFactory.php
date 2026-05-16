<?php

namespace Database\Factories;

use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition(): array
    {
        return [
            'type_price_id' => \App\Models\TypePrice::factory(),
            'product_id' => \App\Models\Product::factory(),
            'product_unit_id' => null,
            'price' => fake()->randomFloat(2, 10, 100),
            'tax_category_id' => null,
            'price_include_tax' => false,
        ];
    }
}
