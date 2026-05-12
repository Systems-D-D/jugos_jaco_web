<?php

namespace Database\Factories;

use App\Models\DetailAssignedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DetailAssignedProduct>
 */
class DetailAssignedProductFactory extends Factory
{
    protected $model = DetailAssignedProduct::class;

    public function definition(): array
    {
        return [
            'assigned_products_id' => AssignedProductFactory::new(),
            'product_id' => ProductFactory::new(),
            'quantity' => 100,
            'sale_quantity' => 0,
            'returned_quantity' => 0,
            'changes_quantity' => 0,
            'royalties_quantity' => 0,
        ];
    }
}
