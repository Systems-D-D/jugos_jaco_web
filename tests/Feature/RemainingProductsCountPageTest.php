<?php

use App\Constants\PermissionConstants;
use App\Enums\ReconciliationStatusEnum;
use App\Enums\UserRole;
use App\Filament\Pages\RemainingProductsCount;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\DailySalesReconciliation;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use App\Services\RemainingProductsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function rpcpCashier(bool $withPermission = true): User
{
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    Permission::findOrCreate(PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE, 'web');

    if ($withPermission) {
        $user->givePermissionTo(PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE);
    }

    return $user;
}

/** Empleado con asignación de hoy: 40 / 30 vendidos / 1 cambio / 2 regalías (máx. 7). */
function rpcpAssignment(string $productName = 'Jugo de Naranja 500ml'): object
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['name' => $productName, 'is_active' => true]);
    FinishedProductInventory::create(['product_id' => $product->id, 'branch_id' => $branch->id, 'stock' => 100, 'min_stock' => 10]);
    $assignment = AssignedProduct::factory()->create(['employee_id' => $employee->id, 'date' => now()]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignment->id,
        'product_id' => $product->id,
        'quantity' => 40, 'sale_quantity' => 30, 'changes_quantity' => 1, 'royalties_quantity' => 2,
        'returned_quantity' => 0,
    ]);

    return (object) compact('branch', 'employee', 'product', 'detail');
}

// ✅ S2 / DoD-1
it('lets the real cashier role open the page', function () {
    // El middleware Authenticate de Filament rechaza a usuarios sin FilamentUser
    // fuera de APP_ENV=local; se simula local para que el resultado dependa
    // solo de canAccess() de la página.
    config(['app.env' => 'local']);
    // Página completa: el menú evalúa las políticas de todos los recursos, que
    // necesitan los permisos sembrados (como en producción).
    $this->seed(RoleSeeder::class);
    $cashier = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $cashier->assignRole(UserRole::CASHEER->value);
    $this->actingAs($cashier);

    $this->get(RemainingProductsCount::getUrl())
        ->assertOk()
        ->assertSee('Conteo de sobrantes')
        ->assertSee('Seleccione un empleado para ver sus productos asignados.');
});

// ❌ S1 / DoD-1
it('forbids the page to a user without DetailAssignedProduct.update', function () {
    // El middleware Authenticate de Filament rechaza a usuarios sin FilamentUser
    // fuera de APP_ENV=local; se simula local para que el resultado dependa
    // solo de canAccess() de la página.
    config(['app.env' => 'local']);
    $this->seed(RoleSeeder::class);
    $this->actingAs(rpcpCashier(withPermission: false));

    $this->get(RemainingProductsCount::getUrl())->assertForbidden();
});

// ✅ Guardar desde la página registra el conteo con counted_by
it('saves a row count with the cashier as counted_by', function () {
    $cashier = rpcpCashier();
    $this->actingAs($cashier);
    $a = rpcpAssignment();

    Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->set("counts.{$a->detail->id}", '5')
        ->call('save', $a->detail->id)
        ->assertNotified('Conteo guardado: Jugo de Naranja 500ml → 5');

    expect((float) $a->detail->fresh()->returned_quantity)->toBe(5.0)
        ->and(ProductReturn::sole()->counted_by)->toBe($cashier->id);
});

// ❌ Cantidad fuera de rango → mensaje claro
it('shows the allowed range when the quantity is too high', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();

    Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->set("counts.{$a->detail->id}", '99')
        ->call('save', $a->detail->id)
        ->assertNotified('La cantidad debe estar entre 0 y 7.');

    expect(ProductReturn::count())->toBe(0);
});

// ⚠️ S3 — empleado sin asignación
it('shows an empty state when the employee has no assignment for the date', function () {
    $this->actingAs(rpcpCashier());
    $employee = Employee::factory()->create();

    Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $employee->id)
        ->assertSee('Sin productos asignados para esta fecha.')
        ->assertSet('rows', []);
});

// 🔁 S4 / BS-5 — cambiar de empleado reinicia filas y valores escritos
it('resets rows and typed values when switching employee', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment('Jugo de Naranja 500ml');
    $b = rpcpAssignment('Jugo de Mango 1L');

    $component = Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->set("counts.{$a->detail->id}", '6')
        ->set('employee_id', (string) $b->employee->id);

    expect(array_keys($component->get('counts')))->toBe([$b->detail->id])
        ->and($component->get('rows')[0]['product_name'])->toBe('Jugo de Mango 1L');
});

// 🔁 Contexto direccionable por URL
it('loads the employee and date from the query string', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();

    Livewire::withQueryParams(['employee' => $a->employee->id, 'date' => now()->toDateString()])
        ->test(RemainingProductsCount::class)
        ->assertSet('employee_id', (string) $a->employee->id)
        ->assertSee('Jugo de Naranja 500ml');
});

// ⚠️ S14 / DoD-4 — cuadre cerrado al cargar → solo lectura
it('renders read only when the reconciliation is completed', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();
    DailySalesReconciliation::factory()->create([
        'employee_id' => $a->employee->id, 'branch_id' => $a->branch->id,
        'reconciliation_date' => now()->toDateString(), 'status' => ReconciliationStatusEnum::COMPLETED,
    ]);

    Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->assertSet('closed', true)
        ->assertSee('El cuadre de este día ya fue cerrado. Solo lectura.')
        ->assertActionHidden('returnAll');
});

// 🔁 S13 / BS-3 — otro usuario cierra mientras el cajero tiene la página abierta
it('blocks the save and switches to read only if the reconciliation was closed meanwhile', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();
    $reconciliation = DailySalesReconciliation::factory()->create([
        'employee_id' => $a->employee->id, 'branch_id' => $a->branch->id,
        'reconciliation_date' => now()->toDateString(), 'status' => ReconciliationStatusEnum::PENDING,
    ]);

    $component = Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->assertSet('closed', false);

    $reconciliation->update(['status' => ReconciliationStatusEnum::COMPLETED]);

    $component->set("counts.{$a->detail->id}", '5')
        ->call('save', $a->detail->id)
        ->assertNotified('El cuadre ya fue cerrado. Pide a un administrador que lo reabra para corregir.')
        ->assertSet('closed', true);

    expect((float) $a->detail->fresh()->returned_quantity)->toBe(0.0);
});

// ❌ S23 / W3 — el permiso se vuelve a verificar en cada acción
it('re-checks the permission on every write', function () {
    $cashier = rpcpCashier();
    $this->actingAs($cashier);
    $a = rpcpAssignment();

    $component = Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id);

    $cashier->revokePermissionTo(PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->set("counts.{$a->detail->id}", '5')
        ->call('save', $a->detail->id)
        ->assertForbidden();

    expect((float) $a->detail->fresh()->returned_quantity)->toBe(0.0);
});

// ✅ Devolver todo con confirmación
it('returns all remaining stock through the confirmed action', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();

    Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->callAction('returnAll')
        ->assertNotified('Se registraron 1 productos como devueltos.');

    expect((float) $a->detail->fresh()->returned_quantity)->toBe(7.0);
});

// ✅ S29 / DoD-8 — la página no muestra dinero
it('never shows money amounts to the cashier', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();

    $component = Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->assertSee('Jugo de Naranja 500ml')
        ->assertDontSee('Precio')
        ->assertDontSee('L.')
        ->assertDontSee('Lps');

    expect(array_keys($component->get('rows')[0]))
        ->not->toContain('shortage_cash')
        ->not->toContain('shortage_cash_unit_price');
});

// ❌ S30 / W6 — un error inesperado no expone detalles técnicos
it('shows a generic message when an unexpected error happens', function () {
    $this->actingAs(rpcpCashier());
    $a = rpcpAssignment();
    $this->mock(RemainingProductsService::class, function ($mock) use ($a) {
        $mock->shouldReceive('listForEmployee')->andReturn([[
            'id' => $a->detail->id, 'product_id' => $a->product->id, 'product_name' => 'Jugo de Naranja 500ml',
            'product_code' => 'NJ5', 'quantity_assigned' => 40.0, 'quantity_sold' => 30.0, 'returned_quantity' => 0.0,
            'changes_quantity' => 1.0, 'royalties_quantity' => 2.0, 'remaining' => 7.0, 'max_returnable' => 7.0,
        ]]);
        $mock->shouldReceive('isClosed')->andReturn(false);
        $mock->shouldReceive('registerCount')->andThrow(new \RuntimeException('SQLSTATE[HY000]: table detail_assigned_products locked'));
    });

    Livewire::test(RemainingProductsCount::class)
        ->set('employee_id', (string) $a->employee->id)
        ->set("counts.{$a->detail->id}", '5')
        ->call('save', $a->detail->id)
        ->assertNotified('No se pudo guardar el conteo. Intente de nuevo.')
        ->assertDontSee('SQLSTATE');
});
