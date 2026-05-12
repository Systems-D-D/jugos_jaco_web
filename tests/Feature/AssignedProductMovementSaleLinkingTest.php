<?php

use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AssignedProductMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Task 2: AssignedProductMovementService tests ---

it('creates movement without sale_id when saleId is null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $service = app(AssignedProductMovementService::class);
    $movement = $service->createMovement($detail->id, 'change', 5, 'Nota de prueba');

    expect($movement->sale_id)->toBeNull();
    expect($movement->quantity)->toEqual(5.0);
    expect($detail->fresh()->changes_quantity)->toEqual(5.0);
});

it('creates movement with sale_id when saleId is provided', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'sale_date' => now(),
        'status' => 'confirmed',
        'subtotal' => 100,
        'total_amount' => 100,
    ]);
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $service = app(AssignedProductMovementService::class);
    $movement = $service->createMovement($detail->id, 'royalty', 3, 'Regalía de venta', $sale->id);

    expect($movement->sale_id)->toEqual($sale->id);
    expect($movement->sale)->not->toBeNull();
    expect($movement->sale->id)->toEqual($sale->id);
    expect($detail->fresh()->royalties_quantity)->toEqual(3.0);
});

it('throws exception when detail does not exist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = app(AssignedProductMovementService::class);

    $this->expectException(Exception::class);
    $service->createMovement(99999, 'change', 1);
});
