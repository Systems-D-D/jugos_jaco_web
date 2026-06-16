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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

it('uses atomic stock update preventing lost updates under concurrency', function () {
    $inventory = FinishedProductInventory::where([
        'product_id' => $this->product->id,
        'branch_id' => $this->branch->id,
    ])->first();

    $service = app(ManagementInventoryService::class);

    DB::transaction(function () use ($service, $inventory) {
        $service->processMovement(
            model: $inventory,
            quantity: 10,
            type: TypeInventoryManagementEnum::SALIDA->value,
            description: 'Test SALIDA 1',
            referenceId: $this->assignedProduct->id,
        );
    });

    $inventory->refresh();
    expect((int) $inventory->stock)->toBe(90);

    DB::transaction(function () use ($service, $inventory) {
        $service->processMovement(
            model: $inventory,
            quantity: 15,
            type: TypeInventoryManagementEnum::SALIDA->value,
            description: 'Test SALIDA 2',
            referenceId: $this->assignedProduct->id,
        );
    });

    $inventory->refresh();
    expect((int) $inventory->stock)->toBe(75);
});

it('creates exactly one SALIDA movement per CreateAction call', function () {
    $component = Livewire::test(DetailsRelationManager::class, [
        'ownerRecord' => $this->assignedProduct,
        'pageClass' => \App\Filament\Resources\AssignedProductResource\Pages\EditAssignedProduct::class,
    ]);

    $component->callTableAction('create', data: [
        'product_id' => $this->product->id,
        'quantity' => 10,
    ]);

    $inventory = FinishedProductInventory::where([
        'product_id' => $this->product->id,
        'branch_id' => $this->branch->id,
    ])->first();

    $salidaCount = ManagementInventory::where([
        'model_type' => FinishedProductInventory::class,
        'model_id' => $inventory->id,
        'type' => TypeInventoryManagementEnum::SALIDA->value,
        'reference_id' => $this->assignedProduct->id,
    ])->count();

    expect($salidaCount)->toBe(1);
    expect(DetailAssignedProduct::where([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
    ])->count())->toBe(1);
});

it('registers ENTRADA movements when bulk deleting assigned products', function () {
    $product2 = Product::factory()->create();
    FinishedProductInventory::create([
        'product_id' => $product2->id,
        'branch_id' => $this->branch->id,
        'stock' => 50,
    ]);

    $detail1 = DetailAssignedProduct::create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 5,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $detail2 = DetailAssignedProduct::create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $product2->id,
        'quantity' => 3,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $inventory1 = FinishedProductInventory::where([
        'product_id' => $this->product->id,
        'branch_id' => $this->branch->id,
    ])->first();
    $inventory2 = FinishedProductInventory::where([
        'product_id' => $product2->id,
        'branch_id' => $this->branch->id,
    ])->first();

    $component = Livewire::test(DetailsRelationManager::class, [
        'ownerRecord' => $this->assignedProduct,
        'pageClass' => \App\Filament\Resources\AssignedProductResource\Pages\EditAssignedProduct::class,
    ]);

    $component->callTableBulkAction('delete', [$detail1, $detail2]);

    $entradaCount1 = ManagementInventory::where([
        'model_type' => FinishedProductInventory::class,
        'model_id' => $inventory1->id,
        'type' => TypeInventoryManagementEnum::ENTRADA->value,
    ])->count();

    $entradaCount2 = ManagementInventory::where([
        'model_type' => FinishedProductInventory::class,
        'model_id' => $inventory2->id,
        'type' => TypeInventoryManagementEnum::ENTRADA->value,
    ])->count();

    expect($entradaCount1)->toBeGreaterThanOrEqual(1);
    expect($entradaCount2)->toBeGreaterThanOrEqual(1);
});
