<?php

use App\Enums\PaymentTermEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\ManagementInventory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductReturn;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\TaxCategory;
use App\Models\TypePrice;
use App\Models\Unit;
use App\Services\ManagementInventoryService;
use App\Services\ProductReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Regresión de la fase 3 del análisis de anulación de ventas
 * (docs/devflow/specs/2026-08-10-sale-deletion-analysis.md §5.2): sin
 * reference_type, la venta #5 y la devolución #5 eran indistinguibles entre
 * los asientos de management_inventory. Estos tests verifican que cada
 * origen de movimiento escribe su propia clase en reference_type.
 */

it('tags a manual entry movement with no reference_type when none is given', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $inventory = FinishedProductInventory::create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'stock' => 10,
    ]);

    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = \App\Models\User::factory()->create(['employee_id' => $employee->id]);
    Auth::login($user);

    $movement = app(ManagementInventoryService::class)->processMovement(
        $inventory,
        5,
        TypeInventoryManagementEnum::ENTRADA->value,
        'Ajuste manual de inventario',
    );

    expect($movement->reference_id)->toBeNull()
        ->and($movement->reference_type)->toBeNull();
});

it('tags the movement with the model class passed as reference_type', function () {
    $branch = Branch::factory()->create();
    $product = Product::factory()->create();
    $inventory = FinishedProductInventory::create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'stock' => 10,
    ]);

    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = \App\Models\User::factory()->create(['employee_id' => $employee->id]);
    Auth::login($user);

    $movement = app(ManagementInventoryService::class)->processMovement(
        $inventory,
        3,
        TypeInventoryManagementEnum::SALIDA->value,
        'Movimiento de prueba',
        referenceId: 42,
        referenceType: Sale::class,
    );

    expect($movement->reference_id)->toBe(42)
        ->and($movement->reference_type)->toBe(Sale::class);
});

it('tags a web sale inventory movement with Sale::class, distinguishing it from a product return with the same id', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = \App\Models\User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $category = \App\Models\Category::factory()->create(['name' => 'Test ' . uniqid()]);
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
    $typePrice = TypePrice::factory()->create();
    $unit = Unit::factory()->create();
    $productUnit = ProductUnit::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
    ]);
    $taxCategory = TaxCategory::create([
        'name' => 'Exento',
        'rate' => 0,
        'is_active' => true,
        'is_for_products' => true,
        'calculation_type' => 'exempt',
    ]);
    $productPrice = ProductPrice::factory()->create([
        'product_id' => $product->id,
        'type_price_id' => $typePrice->id,
        'product_unit_id' => $productUnit->id,
        'tax_category_id' => $taxCategory->id,
    ]);
    $inventory = FinishedProductInventory::create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'stock' => 100,
    ]);

    Auth::login($user);

    // Venta creada desde el flujo "web" (SaleService con inventory_id, sin
    // asignación de producto): genera un asiento de tipo SALIDA referenciando
    // la venta.
    $sale = app(SaleService::class)->createSale(
        [
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'sale_date' => now()->toDateString(),
            'cash_amount' => 100,
            'payment_method' => 'cash',
            'payment_term' => PaymentTermEnum::CASH->value,
            'branch_id' => $branch->id,
        ],
        [[
            'product_id' => $product->id,
            'name' => $product->name,
            'type_price_id' => $typePrice->id,
            'unit_name' => 'Unidad',
            'quantity' => 2,
            'base_quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'inventory_id' => $inventory->id,
        ]],
    );

    $saleMovement = ManagementInventory::where('model_id', $inventory->id)
        ->where('type', TypeInventoryManagementEnum::SALIDA->value)
        ->latest('id')
        ->first();

    expect($saleMovement->reference_id)->toBe($sale->id)
        ->and($saleMovement->reference_type)->toBe(Sale::class);

    // Una devolución con el MISMO id numérico que la venta no debe
    // confundirse con ella: reference_type las distingue.
    $productReturn = ProductReturn::create([
        'product_id' => $product->id,
        'employee_id' => $employee->id,
        'quantity' => 1,
        'type' => 'returned',
        'reason' => 'Prueba de colisión de reference_id',
        'affects_inventory' => true,
    ]);
    // Fuerza el mismo id que la venta para probar la colisión real.
    \DB::table('product_returns')->where('id', $productReturn->id)->update(['id' => $sale->id]);
    $productReturn = ProductReturn::find($sale->id);

    app(ProductReturnService::class)->registerInventoryMovement($productReturn);

    $returnMovement = ManagementInventory::where('model_id', $inventory->id)
        ->where('reference_id', $sale->id)
        ->where('reference_type', ProductReturn::class)
        ->first();

    expect($returnMovement)->not->toBeNull()
        ->and($returnMovement->reference_id)->toBe($saleMovement->reference_id)
        ->and($returnMovement->reference_type)->not->toBe($saleMovement->reference_type);
});

it('tags an assigned-product inventory movement with AssignedProduct::class', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = \App\Models\User::factory()->create(['employee_id' => $employee->id]);
    $product = Product::factory()->create();
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    FinishedProductInventory::create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'stock' => 100,
    ]);

    Auth::login($user);

    foreach ([
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_CREATE,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_VIEW,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_DELETE,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_LIST,
    ] as $perm) {
        \Spatie\Permission\Models\Permission::findOrCreate($perm, 'web');
    }
    $user->givePermissionTo([
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_CREATE,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_VIEW,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_DELETE,
        \App\Constants\PermissionConstants::DETAIL_ASSIGNED_PRODUCT_LIST,
    ]);

    \Livewire\Livewire::test(\App\Filament\Resources\AssignedProductResource\RelationManagers\DetailsRelationManager::class, [
        'ownerRecord' => $assignedProduct,
        'pageClass' => \App\Filament\Resources\AssignedProductResource\Pages\EditAssignedProduct::class,
    ])->callTableAction('create', data: [
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $movement = ManagementInventory::where('reference_id', $assignedProduct->id)
        ->where('type', TypeInventoryManagementEnum::SALIDA->value)
        ->latest('id')
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->reference_type)->toBe(AssignedProduct::class);
});
