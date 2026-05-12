<?php

use App\Models\Client;
use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AssignedProductMovementService;
use App\Services\SaleService;
use Exception;
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

// --- Task 3: SaleService tests ---

it('excludes royalty products from sale totals', function () {
    $service = app(SaleService::class);

    $products = [
        [
            'product_id' => 1,
            'name' => 'Producto Normal',
            'quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 5,
            'line_subtotal' => 100,
            'line_tax_amount' => 10,
        ],
        [
            'product_id' => 2,
            'name' => 'Regalía',
            'quantity' => 1,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 5,
            'line_subtotal' => 50,
            'line_tax_amount' => 5,
            'movement_type' => 'royalty',
        ],
    ];

    $totals = $service->calculateTotals($products);

    expect($totals['subtotal'])->toEqual(100.0);
    expect($totals['total_taxes'])->toEqual(10.0);
    expect($totals['final_total'])->toEqual(110.0);
});

it('excludes change products from sale totals', function () {
    $service = app(SaleService::class);

    $products = [
        [
            'product_id' => 1,
            'name' => 'Producto Normal',
            'quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
        ],
        [
            'product_id' => 2,
            'name' => 'Cambio',
            'quantity' => 1,
            'unit_price_without_tax' => 30,
            'unit_tax_amount' => 0,
            'line_subtotal' => 30,
            'line_tax_amount' => 0,
            'movement_type' => 'change',
        ],
    ];

    $totals = $service->calculateTotals($products);

    expect($totals['subtotal'])->toEqual(100.0);
    expect($totals['final_total'])->toEqual(100.0);
});

it('returns zero totals when all products are movement type', function () {
    $service = app(SaleService::class);

    $products = [
        [
            'product_id' => 1,
            'name' => 'Regalía 1',
            'quantity' => 1,
            'unit_price_without_tax' => 50,
            'line_subtotal' => 50,
            'line_tax_amount' => 0,
            'movement_type' => 'royalty',
        ],
        [
            'product_id' => 2,
            'name' => 'Cambio 1',
            'quantity' => 2,
            'unit_price_without_tax' => 30,
            'line_subtotal' => 60,
            'line_tax_amount' => 0,
            'movement_type' => 'change',
        ],
    ];

    $totals = $service->calculateTotals($products);

    expect($totals['subtotal'])->toEqual(0.0);
    expect($totals['final_total'])->toEqual(0.0);
});

it('creates AssignedProductMovement instead of SaleDetail for royalty product', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();

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

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 100,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'product_id' => $product->id,
            'name' => 'Jugo Naranja',
            'quantity' => 1,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(SaleService::class);
    $sale = $service->createSale($saleData, $productsData);

    expect($sale->subtotal)->toEqual(0.0);
    expect($sale->total_amount)->toEqual(0.0);
    expect($sale->details)->toHaveCount(0);

    $movements = AssignedProductMovement::where('sale_id', $sale->id)->get();
    expect($movements)->toHaveCount(1);
    expect($movements->first()->type->value)->toBe('royalty');
    expect($movements->first()->quantity)->toEqual(1.0);

    expect($detail->fresh()->royalties_quantity)->toEqual(1.0);
});

it('throws exception when no assigned product exists for employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 0,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'product_id' => $product->id,
            'name' => 'Jugo Naranja',
            'quantity' => 1,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(SaleService::class);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('No hay asignación de productos');

    $service->createSale($saleData, $productsData);
});

it('throws exception when product not in assignment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $otherProduct = Product::factory()->create(['name' => 'Otro Producto', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $otherProduct->id,
        'quantity' => 50,
    ]);

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 0,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'product_id' => $product->id,
            'name' => 'Jugo Naranja',
            'quantity' => 1,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(SaleService::class);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('no está asignado al empleado');

    $service->createSale($saleData, $productsData);
});

it('creates SaleDetail for normal products alongside movements for royalty products', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $normalProduct = Product::factory()->create(['name' => 'Jugo Normal', 'is_active' => true]);
    $royaltyProduct = Product::factory()->create(['name' => 'Jugo Regalía', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $normalProduct->id,
        'quantity' => 100,
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $royaltyProduct->id,
        'quantity' => 50,
    ]);

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 100,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'origin' => 'api',
            'product_id' => $normalProduct->id,
            'name' => 'Jugo Normal',
            'quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => null,
        ],
        [
            'product_id' => $royaltyProduct->id,
            'name' => 'Jugo Regalía',
            'quantity' => 1,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 50,
            'line_tax_amount' => 0,
            'line_total' => 50,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(SaleService::class);
    $sale = $service->createSale($saleData, $productsData);

    expect($sale->subtotal)->toEqual(100.0);
    expect($sale->total_amount)->toEqual(100.0);
    expect($sale->details)->toHaveCount(1);
    expect($sale->details->first()->product_name)->toBe('Jugo Normal');

    $movements = AssignedProductMovement::where('sale_id', $sale->id)->get();
    expect($movements)->toHaveCount(1);
    expect($movements->first()->type->value)->toBe('royalty');
});
