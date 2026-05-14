<?php

namespace Database\Factories;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'client_id' => ClientFactory::new(),
            'employee_id' => EmployeeFactory::new(),
            'sale_date' => now(),
            'status' => 'confirmed',
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'payment_term' => 'cash',
            'created_by' => UserFactory::new(),
            'updated_by' => UserFactory::new(),
        ];
    }
}
