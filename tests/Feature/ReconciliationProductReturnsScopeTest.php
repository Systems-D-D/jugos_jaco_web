<?php

use App\Enums\ProductReturnTypeEnum;
use App\Enums\ReconciliationStatusEnum;
use App\Livewire\Reconciliations\CreateReconciliation;
use App\Models\Bill;
use App\Models\Branch;
use App\Models\DailySalesReconciliation;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * La sección de «Devoluciones de productos» del cuadre debe mostrar sólo las
 * devoluciones del día y del empleado que se está cuadrando.
 *
 * El estado del cuadre (current_reconciliation, depósitos, facturas, efectivo
 * recibido) sólo se refrescaba cuando existía un cuadre para el empleado y la
 * fecha elegidos. Al cambiar a un empleado o a una fecha sin cuadre, ese estado
 * se quedaba apuntando al cuadre anterior y la pantalla seguía mostrando sus
 * devoluciones.
 */

function makeReconciliationWithReturn(Employee $employee, Branch $branch, string $date, string $productName): array
{
    $reconciliation = DailySalesReconciliation::factory()->create([
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'reconciliation_date' => $date,
        'status' => ReconciliationStatusEnum::PENDING,
        'total_cash_received' => 500,
    ]);

    $product = Product::factory()->create(['name' => $productName, 'is_active' => true]);

    $return = ProductReturn::create([
        'product_id' => $product->id,
        'employee_id' => $employee->id,
        'reconciliation_id' => $reconciliation->id,
        'quantity' => 5,
        'type' => ProductReturnTypeEnum::DAMAGED,
        'reason' => 'Producto dañado en ruta',
        'affects_inventory' => false,
    ]);

    return compact('reconciliation', 'product', 'return');
}

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->employeeA = Employee::factory()->create(['branch_id' => $this->branch->id]);
    $this->employeeB = Employee::factory()->create(['branch_id' => $this->branch->id]);

    $user = User::factory()->create(['employee_id' => $this->employeeA->id]);
    Auth::login($user);
});

it('clears the returns when switching to an employee that has no reconciliation', function () {
    makeReconciliationWithReturn($this->employeeA, $this->branch, now()->toDateString(), 'Jugo de Naranja 500ml');

    $component = Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employeeA->id]);

    // El empleado A sí tiene devoluciones del día.
    expect($component->get('returns'))->toHaveCount(1);

    // El empleado B no tiene cuadre: no debe heredar las del A.
    $component->set('employee_id', $this->employeeB->id);

    expect($component->get('returns'))->toBeEmpty();
});

it('clears the returns when switching to a date that has no reconciliation', function () {
    makeReconciliationWithReturn($this->employeeA, $this->branch, now()->toDateString(), 'Jugo de Naranja 500ml');

    $component = Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employeeA->id]);

    expect($component->get('returns'))->toHaveCount(1);

    $component->set('reconciliation_date', now()->subDays(3)->format('Y-m-d'));

    expect($component->get('returns'))->toBeEmpty();
});

it('does not leak the previous reconciliation object when the new selection has none', function () {
    $first = makeReconciliationWithReturn($this->employeeA, $this->branch, now()->toDateString(), 'Jugo de Naranja 500ml');

    $component = Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employeeA->id]);

    expect($component->get('current_reconciliation')?->id)->toBe($first['reconciliation']->id);

    $component->set('employee_id', $this->employeeB->id);

    // Sin cuadre para el empleado B, no debe quedar el objeto del A: de ahí
    // salían las devoluciones, depósitos y facturas equivocadas.
    expect($component->get('current_reconciliation'))->toBeNull()
        ->and($component->get('reconciliation_created'))->toBeFalse();
});

it('clears bills and cash received too when the new selection has no reconciliation', function () {
    $first = makeReconciliationWithReturn($this->employeeA, $this->branch, now()->toDateString(), 'Jugo de Naranja 500ml');

    Bill::create([
        'model_id' => $first['reconciliation']->id,
        'description' => 'Combustible',
        'amount' => 250,
        'reference_number' => 'F-001',
        'branch_id' => $this->branch->id,
    ]);

    $component = Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employeeA->id]);

    expect($component->get('bills'))->toHaveCount(1)
        ->and((float) $component->get('cash_received'))->toBe(500.0);

    $component->set('employee_id', $this->employeeB->id);

    expect($component->get('bills'))->toBeEmpty()
        ->and((float) $component->get('cash_received'))->toBe(0.0);
});

it('shows each employee its own returns when both have a reconciliation the same day', function () {
    makeReconciliationWithReturn($this->employeeA, $this->branch, now()->toDateString(), 'Jugo de Naranja 500ml');
    makeReconciliationWithReturn($this->employeeB, $this->branch, now()->toDateString(), 'Jugo de Piña 500ml');

    $component = Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employeeA->id]);

    expect($component->get('returns'))->toHaveCount(1)
        ->and($component->get('returns')[0]['product_name'])->toBe('Jugo de Naranja 500ml');

    $component->set('employee_id', $this->employeeB->id);

    expect($component->get('returns'))->toHaveCount(1)
        ->and($component->get('returns')[0]['product_name'])->toBe('Jugo de Piña 500ml');
});

it('never shows a return that belongs to another employee, even if attached to this reconciliation', function () {
    // Defensa en profundidad: aunque un reconciliation_id quedara mal
    // asignado, la devolución de otro empleado no debe aparecer en su cuadre.
    $first = makeReconciliationWithReturn($this->employeeA, $this->branch, now()->toDateString(), 'Jugo de Naranja 500ml');

    $intruder = Product::factory()->create(['name' => 'Jugo Ajeno 1L', 'is_active' => true]);
    ProductReturn::create([
        'product_id' => $intruder->id,
        'employee_id' => $this->employeeB->id,
        'reconciliation_id' => $first['reconciliation']->id,
        'quantity' => 3,
        'type' => ProductReturnTypeEnum::RETURNED,
        'reason' => 'Devolución de otro empleado',
        'affects_inventory' => false,
    ]);

    $component = Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employeeA->id]);

    expect($component->get('returns'))->toHaveCount(1)
        ->and($component->get('returns')[0]['product_name'])->toBe('Jugo de Naranja 500ml');
});
