<?php

namespace Database\Factories;

use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductUnit>
 */
class ProductUnitFactory extends Factory
{
    protected $model = ProductUnit::class;

    public function definition(): array
    {
        return [
            'product_id' => \App\Models\Product::factory(),
            'unit_id' => UnitFactory::new(),
            'conversion_factor' => 1,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => false,
            'is_active' => true,
        ];
    }
}
