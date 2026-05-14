<?php

namespace Database\Factories;

use App\Models\DailySalesReconciliation;
use App\Enums\ReconciliationStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DailySalesReconciliation>
 */
class DailySalesReconciliationFactory extends Factory
{
    protected $model = DailySalesReconciliation::class;

    public function definition(): array
    {
        return [
            'employee_id' => \App\Models\Employee::factory(),
            'cashier_id' => \App\Models\User::factory(),
            'branch_id' => \App\Models\Branch::factory(),
            'reconciliation_date' => now(),
            'total_cash_sales' => 0,
            'cash_sales' => 0,
            'total_credit_sales' => 0,
            'deposit_sales' => 0,
            'total_sales' => 0,
            'total_cash_received' => 0,
            'total_deposits' => 0,
            'total_collections' => 0,
            'cash_collections' => 0,
            'deposit_collections' => 0,
            'total_cash_expected' => 0,
            'total_deposit_expected' => 0,
            'cash_difference' => 0,
            'deposit_difference' => 0,
            'notes' => null,
            'status' => ReconciliationStatusEnum::PENDING,
            'product_shortage_total' => 0,
            'type_price_id' => null,
        ];
    }
}
