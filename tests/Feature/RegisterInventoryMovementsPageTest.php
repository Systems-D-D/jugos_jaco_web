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
        ])
        ->set('lines', [
                ['inventory_id' => $naranja->id, 'quantity' => 200],
                ['inventory_id' => $pina->id, 'quantity' => 120],
                ['inventory_id' => $mango->id, 'quantity' => 80],
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
        ])
        ->set('lines', [
                ['inventory_id' => $naranja->id, 'quantity' => 50],
                // Esta línea no alcanza: debe tumbar todo el lote.
                ['inventory_id' => $pina->id, 'quantity' => 999],
                ['inventory_id' => $mango->id, 'quantity' => 20],
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
        ])
        ->set('lines', [
                ['inventory_id' => $naranja->id, 'quantity' => 48],
                ['inventory_id' => $mango->id, 'quantity' => 12],
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
        ])
        ->set('lines', [
                ['inventory_id' => $azucar->id, 'quantity' => 300],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect((float) $azucar->fresh()->stock)->toBe(500.0);

    $movement = ManagementInventory::where('model_type', RawMaterialsInventory::class)
        ->where('model_id', $azucar->id)
        ->first();

    expect($movement)->not->toBeNull();
});

it('never adds the same product twice to the batch', function () {
    // La garantía vive en addLine(): dos líneas del mismo producto se
    // sumarían por separado y el usuario no lo vería venir.
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->call('addLine', $naranja->id)
        ->call('addLine', $naranja->id);

    expect($component->get('lines'))->toHaveCount(1);
});

it('hides products already in the batch from the search suggestions', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ]);

    expect($component->instance()->suggestions()->pluck('id'))
        ->toContain($naranja->id)
        ->toContain($mango->id);

    $component->call('addLine', $naranja->id);

    expect($component->instance()->suggestions()->pluck('id'))
        ->not->toContain($naranja->id)
        ->toContain($mango->id);
});

it('filters the suggestions by the search term', function () {
    makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->set('search', 'man');

    $suggestions = $component->instance()->suggestions();

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['id'])->toBe($mango->id);
});

it('adds the first suggestion when pressing enter in the search box', function () {
    makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->set('search', 'man')
        ->call('addFirstSuggestion');

    expect($component->get('lines'))->toHaveCount(1)
        ->and($component->get('lines')[0]['inventory_id'])->toBe($mango->id);

    // Agregar limpia el buscador para encadenar el siguiente producto.
    expect($component->get('search'))->toBe('');
});

it('removes a line from the batch', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $mango = makeInventoryFor($this->branch, 'Jugo de Mango 1L', 112);

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->call('addLine', $naranja->id)
        ->call('addLine', $mango->id)
        ->call('removeLine', 0);

    expect($component->get('lines'))->toHaveCount(1)
        ->and($component->get('lines')[0]['inventory_id'])->toBe($mango->id);
});

it('changing branch clears the batch, because the ids belong to another branch', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);
    $otherBranch = Branch::factory()->create();

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->call('addLine', $naranja->id);

    expect($component->get('lines'))->toHaveCount(1);

    $component->fillForm(['branch_id' => $otherBranch->id]);

    expect($component->get('lines'))->toBeEmpty();
});

it('rejects a line without a quantity', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->set('lines', [
            ['inventory_id' => $naranja->id, 'quantity' => null],
        ])
        ->call('register')
        ->assertHasErrors('lines.0.quantity');

    expect(ManagementInventory::count())->toBe(0);
    expect((float) $naranja->fresh()->stock)->toBe(248.0);
});

it('rejects a line with a zero or negative quantity', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->set('lines', [
            ['inventory_id' => $naranja->id, 'quantity' => 0],
        ])
        ->call('register')
        ->assertHasErrors('lines.0.quantity');

    expect(ManagementInventory::count())->toBe(0);
});

it('does nothing when the batch has no products', function () {
    Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->set('lines', [])
        ->call('register');

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
        ])
        ->set('lines', [
                ['inventory_id' => $naranja->id, 'quantity' => 10],
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
        ])
        ->set('lines', [
                ['inventory_id' => $naranja->id, 'quantity' => 10],
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción del 11/08/2026',
        ]);
});

it('renders the batch UI: search box, lines table and running totals', function () {
    $naranja = makeInventoryFor($this->branch, 'Jugo de Naranja 500ml', 248);

    $component = Livewire::test(RegisterInventoryMovements::class)
        ->fillForm([
            'inventory_type' => RegisterInventoryMovements::TYPE_FINISHED,
            'branch_id' => $this->branch->id,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'description' => 'Producción',
        ])
        ->call('addLine', $naranja->id)
        ->set('lines.0.quantity', 25);

    $component
        // Buscador habilitado (con sucursal elegida ya no dice "elija sucursal").
        ->assertSee('Escriba el nombre del producto')
        // Tabla de líneas con la columna de existencia resultante.
        ->assertSee('Queda en')
        ->assertSee('Jugo de Naranja 500ml')
        // Barra de totales y botón con el conteo.
        ->assertSee('Unidades')
        ->assertSee('Registrar 1 movimiento');
});

it('is hidden from users without inventory update permission', function () {
    $employee = Employee::factory()->create();
    $outsider = User::factory()->create(['employee_id' => $employee->id]);
    Auth::login($outsider);

    expect(RegisterInventoryMovements::canAccess())->toBeFalse();
    expect(RegisterInventoryMovements::shouldRegisterNavigation())->toBeFalse();
});
