<?php

use App\Enums\ProductReturnTypeEnum;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function prctBase(): array
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $product = Product::factory()->create(['is_active' => true]);
    $assignment = AssignedProduct::factory()->create(['employee_id' => $employee->id, 'date' => now()]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignment->id,
        'product_id' => $product->id,
    ]);

    return compact('employee', 'user', 'product', 'detail');
}

// ✅ Happy path
it('stores who counted and which assignment detail a return adjusts', function () {
    ['employee' => $employee, 'user' => $user, 'product' => $product, 'detail' => $detail] = prctBase();

    $return = ProductReturn::create([
        'product_id' => $product->id,
        'employee_id' => $employee->id,
        'counted_by' => $user->id,
        'detail_assigned_product_id' => $detail->id,
        'quantity' => 5,
        'type' => ProductReturnTypeEnum::RETURNED,
        'reason' => 'Conteo de sobrantes',
        'affects_inventory' => false,
    ])->fresh();

    expect($return->countedBy->is($user))->toBeTrue()
        ->and($return->detailAssignedProduct->is($detail))->toBeTrue();
});

// ⚠️ Edge case
it('keeps both tracking columns nullable for manual and legacy returns', function () {
    ['employee' => $employee, 'product' => $product] = prctBase();

    $return = ProductReturn::create([
        'product_id' => $product->id,
        'employee_id' => $employee->id,
        'quantity' => 2,
        'type' => ProductReturnTypeEnum::DAMAGED,
        'reason' => 'Producto dañado en ruta',
        'affects_inventory' => false,
    ])->fresh();

    expect($return->counted_by)->toBeNull()
        ->and($return->detail_assigned_product_id)->toBeNull();
});

// ⚠️ Edge case — las correcciones se guardan con signo
it('stores negative quantities for count corrections', function () {
    ['employee' => $employee, 'user' => $user, 'product' => $product, 'detail' => $detail] = prctBase();

    $return = ProductReturn::create([
        'product_id' => $product->id,
        'employee_id' => $employee->id,
        'counted_by' => $user->id,
        'detail_assigned_product_id' => $detail->id,
        'quantity' => -2,
        'type' => ProductReturnTypeEnum::RETURNED,
        'reason' => 'Corrección de conteo de sobrantes',
        'affects_inventory' => false,
    ])->fresh();

    expect((float) $return->quantity)->toBe(-2.0);
});

// ❌ Failure — la FK rechaza un usuario inexistente
it('rejects a counted_by that does not exist', function () {
    ['employee' => $employee, 'product' => $product] = prctBase();

    ProductReturn::create([
        'product_id' => $product->id,
        'employee_id' => $employee->id,
        'counted_by' => 999999,
        'quantity' => 1,
        'type' => ProductReturnTypeEnum::RETURNED,
        'reason' => 'Conteo de sobrantes',
        'affects_inventory' => false,
    ]);
})->throws(\Illuminate\Database\QueryException::class);
