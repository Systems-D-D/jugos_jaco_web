<?php

namespace Database\Factories;

use App\Models\AssignedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AssignedProduct>
 */
class AssignedProductFactory extends Factory
{
    protected $model = AssignedProduct::class;

    public function definition(): array
    {
        return [
            'employee_id' => EmployeeFactory::new(),
            'date' => now(),
        ];
    }
}
