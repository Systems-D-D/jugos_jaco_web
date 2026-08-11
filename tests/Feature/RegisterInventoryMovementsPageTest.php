<?php

use App\Constants\PermissionConstants;
use App\Enums\TypeInventoryManagementEnum;
use App\Filament\Pages\RegisterInventoryMovements;
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
 * Opción C del prototipo de UX: cargar varios movimientos en una sola pasada.
 * Lo importante a proteger aquí es el "todo o nada": si una línea falla, no
 * debe quedar registrada ninguna.
 */

function grantBulkInventoryPermissions(User $user): void
{
    $permissions = [
        PermissionConstants::FINISHED_PRODUCT_INVENTORY_UPDATE,
        PermissionConstants::RAW_MATERIALS_INVENTORY_UPDATE,
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo($permissions);
}

function makeInventoryFor(Branch $branch, string $productName, float $stock): FinishedProductInventory
{
    $product = Product::factory()->create(['name' => $productName, 'is_active' => true]);

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
    grantBulkInventoryPermissions($this->user);
    Auth::login($this->user);

    $this->branch = Branch::factory()->create();
});

it('registers a batch of entradas in one pass', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $pina = makeInventoryFor($this->branch, 'Jugo de Piña 500ml', 36);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción del 11/08/2026',
            'lines' => [
                ['inventory_id' => $naranja->id, 'quantity' => 200],
                ['inventory_id' => $pina->id, 'quantity' => 120],
                ['inventory_id' => $mango->id, 'quantity' => 80],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect((float) $naranja->fresh()->stock)->toBe(448.0)
        ->and((float) $pina->fresh()->stock)->toBe(156.0)
        ->and((float) $mango->fresh()->stock)->toBe(192.0);

    expect(ManagementInventory::count())->toBe(3);
});

it('rolls back the whole batch when one line has insufficient stock', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $pina = makeInventoryFor($this->branch, 'Jugo de Piña 500ml', 10);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::SALIDA->value,
            'description' => 'Traslado a sucursal norte',
            'lines' => [
                ['inventory_id' => $naranja->id, 'quantity' => 50],
                // Esta línea no alcanza: debe tumbar todo el lote.
                ['inventory_id' => $pina->id, 'quantity' => 999],
                ['inventory_id' => $mango->id, 'quantity' => 20],
            ],
        ])
        ->call('register');

    // Ni siquiera la primera línea, que sí tenía existencia, quedó aplicada.
    expect((float) $naranja->fresh()->stock)->toBe(248.0)
        ->and((float) $pina->fresh()->stock)->toBe(10.0)
        ->and((float) $mango->fresh()->stock)->toBe(112.0);

    expect(ManagementInventory::count())->toBe(0);
});

it('registers a batch of salidas and decreases every stock', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::SALIDA->value,
            'description' => 'Traslado',
            'lines' => [
                ['inventory_id' => $naranja->id, 'quantity' => 48],
                ['inventory_id' => $mango->id, 'quantity' => 12],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect((float) $naranja->fresh()->stock)->toBe(200.0)
        ->and((float) $mango->fresh()->stock)->toBe(100.0);
});

it('works with the raw materials inventory too', function () {
    $azucar = RawMaterialsInventory::create([
        'name' => 'Azúcar',
        'unit_type' => 'Libra',
        'stock' => 200,
        'min_stock' => 50,
        'branch_id' => $this->branch->id,
    ]);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_RAW,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Compra semanal',
            'lines' => [
                ['inventory_id' => $azucar->id, 'quantity' => 300],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect((float) $azucar->fresh()->stock)->toBe(500.0);

    $movement = ManagementInventory::where('model_type', RawMaterialsInventory::class)
        ->where('model_id', $azucar->id)
        ->first();

    expect($movement)->not->toBeNull();
});

it('rejects the same product twice in the batch', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
            'lines' => [
                ['inventory_id' => $naranja->id, 'quantity' => 10],
                ['inventory_id' => $naranja->id, 'quantity' => 20],
            ],
        ])
        ->call('register')
        ->assertHasFormErrors();

    expect((float) $naranja->fresh()->stock)->toBe(248.0);
    expect(ManagementInventory::count())->toBe(0);
});

it('requires a description for the batch', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => '',
            'lines' => [
                ['inventory_id' => $naranja->id, 'quantity' => 10],
            ],
        ])
        ->call('register')
        ->assertHasFormErrors(['description']);

    expect(ManagementInventory::count())->toBe(0);
});

it('keeps the batch header after a successful save so another batch can follow', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción del 11/08/2026',
            'lines' => [
                ['inventory_id' => $naranja->id, 'quantity' => 10],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción del 11/08/2026',
        ]);
});

it('is hidden from users without inventory update permission', function () {
    $employee = Employee::factory()->create();
    $outsider = User::factory()->create(['employee_id' => $employee->id]);
    Auth::login($outsider);

    expect(RegisterInventoryMovements::canAccess())->toBeFalse();
    expect(RegisterInventoryMovements::shouldRegisterNavigation())->toBeFalse();
});
