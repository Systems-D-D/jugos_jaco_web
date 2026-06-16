<?php

use App\Constants\PermissionConstants;
use App\Enums\TypeInventoryManagementEnum;
use App\Filament\Resources\AssignedProductResource\RelationManagers\DetailsRelationManager;
use App\Models\AssignedProduct;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\ManagementInventory;
use App\Models\Product;
use App\Models\User;
use App\Services\ManagementInventoryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $branch = \App\Models\Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
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

    // Grant Filament permissions the relation manager will check at render time
    foreach ([
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_CREATE,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_VIEW,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_DELETE,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_LIST,
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    $user->givePermissionTo([
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_CREATE,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_VIEW,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_DELETE,
        PermissionConstants::DETAIL_ASSIGNED_PRODUCT_LIST,
    ]);

    Auth::login($user);

    $this->branch = $branch;
    $this->employee = $employee;
    $this->user = $user;
    $this->product = $product;
    $this->assignedProduct = $assignedProduct;
});

// ✅ DB-level: unique index on (assigned_products_id, product_id) rejects duplicates
it('rejects a duplicate (assigned_product, product) row at the database level', function () {
    DetailAssignedProduct::create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    // Same (assigned_product, product) pair must throw QueryException
    expect(fn () => DetailAssignedProduct::create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]))->toThrow(QueryException::class);

    // Only one row should exist for that pair
    expect(DetailAssignedProduct::where([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
    ])->count())->toBe(1);
});

// ✅ App-level: CreateAction::before() guard rejects duplicate product with ValidationException
it('rejects a duplicate product in the DetailsRelationManager create flow', function () {
    DetailAssignedProduct::create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    // Mount the relation manager against the existing assigned product
    $component = Livewire::test(DetailsRelationManager::class, [
        'ownerRecord' => $this->assignedProduct,
        'pageClass' => \App\Filament\Resources\AssignedProductResource\Pages\EditAssignedProduct::class,
    ]);

    // Submit the CreateAction form with the SAME product — Filament should reject it
    $component->callTableAction('create', data: [
        'product_id' => $this->product->id,
        'quantity' => 3,
    ]);

    // Verify the guard fired: still only one row exists (duplicate was rejected)
    expect(DetailAssignedProduct::where([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
    ])->count())->toBe(1);
});

// ✅ Inventory idempotency: blocked duplicate does NOT register a second SALIDA movement
it('does not register a duplicate SALIDA movement when a duplicate assignment is blocked', function () {
    DetailAssignedProduct::create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    // Manually simulate the SALIDA that the CreateAction::after() would have registered
    // (mirrors what managementInventoryCreateDetail() does) for the original record
    $product = FinishedProductInventory::where([
        'product_id' => $this->product->id,
        'branch_id' => $this->branch->id,
    ])->first();

    app(ManagementInventoryService::class)->processMovement(
        model: $product,
        quantity: 5,
        type: TypeInventoryManagementEnum::SALIDA->value,
        description: 'Asignación de producto al empleado',
        referenceId: $this->assignedProduct->id,
    );

    $salidaCountBefore = ManagementInventory::where([
        'model_type' => FinishedProductInventory::class,
        'model_id' => $product->id,
        'type' => TypeInventoryManagementEnum::SALIDA->value,
    ])->count();

    // Now attempt to create a duplicate via the relation manager
    $component = Livewire::test(DetailsRelationManager::class, [
        'ownerRecord' => $this->assignedProduct,
        'pageClass' => \App\Filament\Resources\AssignedProductResource\Pages\EditAssignedProduct::class,
    ]);

    $component->callTableAction('create', data: [
        'product_id' => $this->product->id,
        'quantity' => 3,
    ]);

    $salidaCountAfter = ManagementInventory::where([
        'model_type' => FinishedProductInventory::class,
        'model_id' => $product->id,
        'type' => TypeInventoryManagementEnum::SALIDA->value,
    ])->count();

    // No second SALIDA was registered
    expect($salidaCountAfter)->toBe($salidaCountBefore);
    expect($salidaCountAfter)->toBe(1);
});
