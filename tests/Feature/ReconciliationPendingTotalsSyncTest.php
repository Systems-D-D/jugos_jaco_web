<?php

use App\Constants\PermissionConstants;
use App\Enums\ReconciliationStatusEnum;
use App\Filament\Resources\DailySalesReconciliationResource\Pages\ListDailySalesReconciliations;
use App\Livewire\Reconciliations\CreateReconciliation;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\DailySalesReconciliation;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Sale;
use App\Models\User;
use App\Services\RemainingProductsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function rpstScenario(): object
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create(['branch_id' => $branch->id])->id]);
    $product = Product::factory()->create(['name' => 'Jugo de Naranja 500ml', 'is_active' => true]);
    $inventory = FinishedProductInventory::create(['product_id' => $product->id, 'branch_id' => $branch->id, 'stock' => 100, 'min_stock' => 10]);
    $assignment = AssignedProduct::factory()->create(['employee_id' => $employee->id, 'date' => now()]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignment->id, 'product_id' => $product->id,
        'quantity' => 40, 'sale_quantity' => 30, 'changes_quantity' => 1, 'royalties_quantity' => 2, 'returned_quantity' => 0,
    ]);

    return (object) compact('branch', 'employee', 'user', 'product', 'inventory', 'detail');
}

function rpstPending(object $s, array $attributes = []): DailySalesReconciliation
{
    return DailySalesReconciliation::factory()->create(array_merge([
        'employee_id' => $s->employee->id,
        'cashier_id' => $s->user->id,
        'branch_id' => $s->branch->id,
        'reconciliation_date' => now()->toDateString(),
        'status' => ReconciliationStatusEnum::PENDING,
    ], $attributes));
}

// ✅ S24 / DoD-6 — abrir un pendiente con ceros guarda los totales reales
it('persists the current totals when a zeroed pending reconciliation is opened', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);
    $reconciliation = rpstPending($s); // totales en 0 (como lo deja el cajero)
    Sale::factory()->create([
        'employee_id' => $s->employee->id, 'sale_date' => now(),
        'subtotal' => 150, 'total_amount' => 150, 'payment_term' => 'cash', 'payment_method' => 'cash',
    ]);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id]);

    expect((float) $reconciliation->fresh()->total_sales)->toBe(150.0)
        ->and((float) $reconciliation->fresh()->total_cash_expected)->toBe(150.0);
});

// 🔁 S25 — un cambio dentro del cuadre persiste los totales
it('persists the totals after adding a bill to a pending reconciliation', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);
    $reconciliation = rpstPending($s);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id])
        ->set('bill_description', 'Combustible')
        ->set('bill_amount', 40)
        ->set('bill_reference', 'FAC-001')
        ->call('saveBill')
        ->assertHasNoErrors();

    // Los gastos no tienen columna propia: se descuentan del efectivo esperado.
    expect((float) $reconciliation->fresh()->total_cash_expected)->toBe(-40.0);
});

// ⚠️ S26 / I4 — un cuadre cerrado nunca se reescribe
it('never rewrites the totals of a completed reconciliation', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);
    $reconciliation = rpstPending($s, ['status' => ReconciliationStatusEnum::COMPLETED, 'total_sales' => 80]);
    Sale::factory()->create(['employee_id' => $s->employee->id, 'sale_date' => now(), 'subtotal' => 150, 'total_amount' => 150]);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id]);

    expect((float) $reconciliation->fresh()->total_sales)->toBe(80.0);
});

// ✅ S28 / DoD-7 — mount acepta la fecha del cuadre
it('mounts on the reconciliation date passed in', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);

    Livewire::test(CreateReconciliation::class, [
        'employee_id' => $s->employee->id,
        'reconciliation_date' => '2026-11-20',
    ])->assertSet('reconciliation_date', '2026-11-20');
});

// ⚠️ S28 — una fecha inválida cae a hoy
it('falls back to today when the reconciliation date is invalid', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);

    Livewire::test(CreateReconciliation::class, [
        'employee_id' => $s->employee->id,
        'reconciliation_date' => '2026-02-31',
    ])->assertSet('reconciliation_date', now()->format('Y-m-d'));
});

// ❌ S31 / DoD-4 — el cuadre tampoco puede cambiar el conteo si está cerrado
it('refuses to change returned quantities from a completed reconciliation', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);
    rpstPending($s, ['status' => ReconciliationStatusEnum::COMPLETED]);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id])
        ->call('updateReturnedQuantity', $s->detail->id, 5)
        ->assertSee('El cuadre ya fue cerrado. Pide a un administrador que lo reabra para corregir.');

    expect((float) $s->detail->fresh()->returned_quantity)->toBe(0.0)
        ->and(ProductReturn::count())->toBe(0);
});

// ✅ El cuadre registra el conteo vía servicio (corrección por diferencia)
it('registers counts from the reconciliation through the shared service', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id])
        ->call('updateReturnedQuantity', $s->detail->id, 5)
        ->call('updateReturnedQuantity', $s->detail->id, 3);

    expect(ProductReturn::orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([5.0, -2.0])
        ->and(ProductReturn::first()->counted_by)->toBe($s->user->id)
        ->and((float) $s->inventory->fresh()->stock)->toBe(103.0);
});

// 🔁 S32 / DoD-5 — borrar desde el cuadre ajusta el detalle
it('restores the detail when a correction is deleted from the reconciliation', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);
    $service = app(RemainingProductsService::class);
    $service->registerCount($s->employee->id, now()->toDateString(), $s->detail->id, 5, $s->user);
    $correction = $service->registerCount($s->employee->id, now()->toDateString(), $s->detail->id, 3, $s->user);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id])
        ->call('deleteReturn', $correction->id);

    expect((float) $s->detail->fresh()->returned_quantity)->toBe(5.0)
        ->and(ProductReturn::count())->toBe(1);
});

// ❌ S33 / W2 — no se puede borrar una devolución de otro cuadre
it('refuses to delete a return that belongs to another reconciliation', function () {
    $s = rpstScenario();
    $this->actingAs($s->user);
    rpstPending($s);
    $other = rpstScenario();
    $foreign = app(RemainingProductsService::class)
        ->registerCount($other->employee->id, now()->toDateString(), $other->detail->id, 5, $other->user);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $s->employee->id])
        ->call('deleteReturn', $foreign->id)
        ->assertSee('La devolución no pertenece a este cuadre.');

    expect(ProductReturn::find($foreign->id))->not->toBeNull();
});

// ✅ S27 / DoD-7 — "Editar" lleva empleado y fecha
it('links the edit action of a pending reconciliation to its own date', function () {
    $s = rpstScenario();
    foreach (PermissionConstants::getCrudPermissions(PermissionConstants::MODEL_DAILY_SALES_RECONCILIATION) as $permission) {
        Permission::findOrCreate($permission, 'web');
        $s->user->givePermissionTo($permission);
    }
    $this->actingAs($s->user);
    $reconciliation = rpstPending($s, ['reconciliation_date' => '2026-11-20']);

    Livewire::test(ListDailySalesReconciliations::class)
        ->assertTableActionHasUrl('edit_pending', route('filament.admin.resources.daily-sales-reconciliations.create', [
            'employee_id' => $s->employee->id,
            'reconciliation_date' => '2026-11-20',
        ]), record: $reconciliation);
});

// ✅ La página de creación pasa la fecha al componente
it('passes the reconciliation date from the query string to the component', function () {
    // El middleware Authenticate de Filament rechaza a usuarios sin FilamentUser
    // fuera de APP_ENV=local; se simula local para que el resultado dependa
    // solo de canAccess() de la página.
    config(['app.env' => 'local']);
    // Página completa: el menú evalúa las políticas de todos los recursos.
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $s = rpstScenario();
    foreach (PermissionConstants::getCrudPermissions(PermissionConstants::MODEL_DAILY_SALES_RECONCILIATION) as $permission) {
        Permission::findOrCreate($permission, 'web');
        $s->user->givePermissionTo($permission);
    }
    $this->actingAs($s->user);

    $this->get(route('filament.admin.resources.daily-sales-reconciliations.create', [
        'employee_id' => $s->employee->id,
        'reconciliation_date' => '2026-11-20',
    ]))->assertOk()->assertSee('2026-11-20');
});
