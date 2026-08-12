<?php

use App\Constants\PermissionConstants;
use App\Enums\TypeInventoryManagementEnum;
use App\Filament\Resources\FinishedProductInventoryResource\Pages\ListFinishedProductInventories;
use App\Filament\Resources\RawMaterialsInventoryResource\Pages\ListRawMaterialsInventories;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\ManagementInventory;
use App\Models\Product;
use App\Models\RawMaterialsInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Opción A del prototipo de UX: registrar un movimiento desde la propia fila
 * de la tabla de inventario, sin entrar al registro. Toda la lógica sigue en
 * ManagementInventoryService; esta acción sólo lo expone.
 */

function grantInventoryPermissions(User $user): void
{
    $permissions = [
        PermissionConstants::FINISHED_PRODUCT_INVENTORY_LIST,
        PermissionConstants::FINISHED_PRODUCT_INVENTORY_VIEW,
        PermissionConstants::FINISHED_PRODUCT_INVENTORY_CREATE,
        PermissionConstants::FINISHED_PRODUCT_INVENTORY_UPDATE,
        PermissionConstants::FINISHED_PRODUCT_INVENTORY_DELETE,
        PermissionConstants::RAW_MATERIALS_INVENTORY_LIST,
        PermissionConstants::RAW_MATERIALS_INVENTORY_VIEW,
        PermissionConstants::RAW_MATERIALS_INVENTORY_CREATE,
        PermissionConstants::RAW_MATERIALS_INVENTORY_UPDATE,
        PermissionConstants::RAW_MATERIALS_INVENTORY_DELETE,
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo($permissions);
}

function makeFinishedInventory(float $stock = 100): FinishedProductInventory
{
    $branch = Branch::factory()->create();
    $product = Product::factory()->create(['name' => 'Jugo de Piña 500ml', 'is_active' => true]);

    return FinishedProductInventory::create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'stock' => $stock,
        'min_stock' => 10,
    ]);
}

beforeEach(function () {
    $employee = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $employee->id]);
    grantInventoryPermissions($this->user);
    Auth::login($this->user);
});

it('registers an entrada from the finished product inventory row and increases stock', function () {
    $inventory = makeFinishedInventory(100);

    Livewire::test(ListFinishedProductInventories::class)
        ->callTableAction('register_movement', $inventory, data: [
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'quantity' => 120,
            'description' => 'Producción del día',
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $inventory->fresh()->stock)->toBe(220.0);

    $movement = ManagementInventory::where('model_id', $inventory->id)
        ->where('model_type', FinishedProductInventory::class)
        ->latest('id')
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(TypeInventoryManagementEnum::ENTRADA->value)
        ->and((float) $movement->quantity)->toBe(120.0)
        ->and($movement->description)->toBe('Producción del día');
});

it('registers a salida and decreases stock', function () {
    $inventory = makeFinishedInventory(100);

    Livewire::test(ListFinishedProductInventories::class)
        ->callTableAction('register_movement', $inventory, data: [
            'type' => TypeInventoryManagementEnum::SALIDA->value,
            'quantity' => 40,
            'description' => 'Traslado a sucursal norte',
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $inventory->fresh()->stock)->toBe(60.0);
});

it('rejects a salida larger than the current stock without touching it', function () {
    $inventory = makeFinishedInventory(30);

    Livewire::test(ListFinishedProductInventories::class)
        ->callTableAction('register_movement', $inventory, data: [
            'type' => TypeInventoryManagementEnum::SALIDA->value,
            'quantity' => 50,
            'description' => 'Intento de salida sin existencia',
        ])
        ->assertHasTableActionErrors(['quantity']);

    expect((float) $inventory->fresh()->stock)->toBe(30.0);
    expect(ManagementInventory::count())->toBe(0);
});

it('allows an entrada larger than the current stock: only exits are capped', function () {
    $inventory = makeFinishedInventory(5);

    Livewire::test(ListFinishedProductInventories::class)
        ->callTableAction('register_movement', $inventory, data: [
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'quantity' => 500,
            'description' => 'Reabastecimiento',
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $inventory->fresh()->stock)->toBe(505.0);
});

it('requires a description', function () {
    $inventory = makeFinishedInventory(100);

    Livewire::test(ListFinishedProductInventories::class)
        ->callTableAction('register_movement', $inventory, data: [
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'quantity' => 10,
            'description' => '',
        ])
        ->assertHasTableActionErrors(['description']);

    expect((float) $inventory->fresh()->stock)->toBe(100.0);
});

it('works the same on the raw materials inventory, which measures in its own unit', function () {
    $branch = Branch::factory()->create();
    $rawMaterial = RawMaterialsInventory::create([
        'name' => 'Azúcar',
        'unit_type' => 'Libra',
        'stock' => 200,
        'min_stock' => 50,
        'branch_id' => $branch->id,
    ]);

    Livewire::test(ListRawMaterialsInventories::class)
        ->callTableAction('register_movement', $rawMaterial, data: [
            'type' => TypeInventoryManagementEnum::DANADO->value,
            'quantity' => 15,
            'description' => 'Saco roto en bodega',
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $rawMaterial->fresh()->stock)->toBe(185.0);

    $movement = ManagementInventory::where('model_id', $rawMaterial->id)
        ->where('model_type', RawMaterialsInventory::class)
        ->latest('id')
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->type)->toBe(TypeInventoryManagementEnum::DANADO->value);
});
