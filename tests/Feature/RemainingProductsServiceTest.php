<?php

use App\Enums\ProductReturnTypeEnum;
use App\Enums\ReconciliationStatusEnum;
use App\Exceptions\RemainingProductCountException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Asignación de hoy: 40 asignados, 30 vendidos, 1 cambio, 2 regalías →
 * máximo devolvible 7. Inventario de la sucursal con $stock unidades.
 */
function rpsScenario(array $detailAttributes = [], float $stock = 100): object
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $cashier = User::factory()->create([
        'employee_id' => Employee::factory()->create(['branch_id' => $branch->id])->id,
    ]);
    Auth::login($cashier);

    $product = Product::factory()->create(['name' => 'Jugo de Naranja 500ml', 'is_active' => true]);
    $inventory = FinishedProductInventory::create([
        'product_id' => $product->id,
        'branch_id' => $branch->id,
        'stock' => $stock,
        'min_stock' => 10,
    ]);
    $assignment = AssignedProduct::factory()->create(['employee_id' => $employee->id, 'date' => now()]);
    $detail = DetailAssignedProduct::factory()->create(array_merge([
        'assigned_products_id' => $assignment->id,
        'product_id' => $product->id,
        'quantity' => 40,
        'sale_quantity' => 30,
        'changes_quantity' => 1,
        'royalties_quantity' => 2,
        'returned_quantity' => 0,
    ], $detailAttributes));

    return (object) [
        'branch' => $branch,
        'employee' => $employee,
        'cashier' => $cashier,
        'product' => $product,
        'inventory' => $inventory,
        'assignment' => $assignment,
        'detail' => $detail,
        'date' => now()->toDateString(),
        'service' => app(RemainingProductsService::class),
    ];
}

function rpsCount(object $s, float $quantity, ?int $detailId = null): ?ProductReturn
{
    return $s->service->registerCount($s->employee->id, $s->date, $detailId ?? $s->detail->id, $quantity, $s->cashier);
}

function rpsStock(object $s): float
{
    return (float) $s->inventory->fresh()->stock;
}

// ✅ S5 / DoD-2 — el primer conteo crea el cuadre pendiente con totales en 0
it('creates the pending reconciliation and a counted return on the first count', function () {
    $s = rpsScenario();

    $return = rpsCount($s, 5);

    $reconciliation = DailySalesReconciliation::sole();
    expect($reconciliation->status)->toBe(ReconciliationStatusEnum::PENDING)
        ->and((float) $reconciliation->total_sales)->toBe(0.0)
        ->and((float) $reconciliation->total_cash_expected)->toBe(0.0)
        ->and($reconciliation->cashier_id)->toBe($s->cashier->id)
        ->and($return->counted_by)->toBe($s->cashier->id)
        ->and($return->detail_assigned_product_id)->toBe($s->detail->id)
        ->and($return->reconciliation_id)->toBe($reconciliation->id)
        ->and((float) $return->quantity)->toBe(5.0)
        ->and($return->reason)->toBe(RemainingProductsService::REASON_COUNT)
        ->and((float) $s->detail->fresh()->returned_quantity)->toBe(5.0)
        ->and(rpsStock($s))->toBe(105.0);
});

// 🔁 S6 / DoD-3 / BS-1 — corregir 5 → 3 registra solo la diferencia
it('records only the difference when a count is corrected', function () {
    $s = rpsScenario();

    rpsCount($s, 5);
    $correction = rpsCount($s, 3);

    expect(ProductReturn::orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([5.0, -2.0])
        ->and($correction->reason)->toBe(RemainingProductsService::REASON_CORRECTION)
        ->and((float) $s->detail->fresh()->returned_quantity)->toBe(3.0)
        ->and(rpsStock($s))->toBe(103.0);
});

// 🔁 S7 / BS-2 — repetir el mismo valor no duplica nada
it('does nothing when the same quantity is saved twice', function () {
    $s = rpsScenario();

    rpsCount($s, 3);
    $second = rpsCount($s, 3);

    expect($second)->toBeNull()
        ->and(ProductReturn::count())->toBe(1)
        ->and(rpsStock($s))->toBe(103.0);
});

// ⚠️ S8 — corregir a 0 genera una devolución negativa por todo lo registrado
it('creates a full negative correction when the count is set back to zero', function () {
    $s = rpsScenario();

    rpsCount($s, 5);
    rpsCount($s, 0);

    expect((float) ProductReturn::latest('id')->first()->quantity)->toBe(-5.0)
        ->and((float) $s->detail->fresh()->returned_quantity)->toBe(0.0)
        ->and(rpsStock($s))->toBe(100.0);
});

// ❌ S9 — cantidades fuera de rango
it('rejects a quantity above the returnable maximum', function () {
    $s = rpsScenario();

    expect(fn () => rpsCount($s, 8))->toThrow(RemainingProductCountException::class, 'La cantidad debe estar entre 0 y 7.');
    expect(ProductReturn::count())->toBe(0)
        ->and(DailySalesReconciliation::count())->toBe(0)
        ->and((float) $s->detail->fresh()->returned_quantity)->toBe(0.0);
});

it('rejects a negative quantity', function () {
    $s = rpsScenario();

    expect(fn () => rpsCount($s, -1))->toThrow(RemainingProductCountException::class);
    expect(ProductReturn::count())->toBe(0);
});

// ⚠️ S10 — detalle histórico con máximo negativo (datos previos a 93ec558)
it('treats a negative maximum as zero for legacy inflated details', function () {
    $s = rpsScenario(['quantity' => 80, 'sale_quantity' => 80, 'changes_quantity' => 0, 'royalties_quantity' => 6]);

    expect(fn () => rpsCount($s, 1))->toThrow(RemainingProductCountException::class, 'La cantidad debe estar entre 0 y 0.');
    expect(rpsCount($s, 0))->toBeNull();
});

// ❌ S11 / W2 — el detalle debe ser del empleado y la fecha
it('rejects a detail that belongs to another employee', function () {
    $s = rpsScenario();
    $otherAssignment = AssignedProduct::factory()->create([
        'employee_id' => Employee::factory()->create(['branch_id' => $s->branch->id])->id,
        'date' => now(),
    ]);
    $foreignDetail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $otherAssignment->id,
        'product_id' => $s->product->id,
        'quantity' => 10,
    ]);

    expect(fn () => rpsCount($s, 1, $foreignDetail->id))
        ->toThrow(RemainingProductCountException::class, 'El producto no pertenece a la asignación seleccionada.');
    expect(DailySalesReconciliation::count())->toBe(0)
        ->and((float) $foreignDetail->fresh()->returned_quantity)->toBe(0.0);
});

it('rejects a detail from an assignment of another date', function () {
    $s = rpsScenario();
    $s->assignment->update(['date' => now()->subDay()]);

    expect(fn () => rpsCount($s, 1))->toThrow(RemainingProductCountException::class);
});

// ❌ S12 / DoD-4 — cuadre cerrado
it('refuses to change the count of a completed reconciliation', function () {
    $s = rpsScenario();
    DailySalesReconciliation::factory()->create([
        'employee_id' => $s->employee->id,
        'branch_id' => $s->branch->id,
        'reconciliation_date' => $s->date,
        'status' => ReconciliationStatusEnum::COMPLETED,
    ]);

    expect(fn () => rpsCount($s, 5))->toThrow(RemainingProductCountException::class, 'El cuadre ya fue cerrado');
    expect(ProductReturn::count())->toBe(0)
        ->and((float) $s->detail->fresh()->returned_quantity)->toBe(0.0)
        ->and(rpsStock($s))->toBe(100.0);
});

// 🔁 S15 / BS-4 — la diferencia se calcula contra lo guardado, no contra la pantalla
it('computes the difference against the stored value even if the screen was stale', function () {
    $s = rpsScenario();
    $screenRows = $s->service->listForEmployee($s->employee->id, $s->date); // la pantalla ve 0

    rpsCount($s, 5); // otro usuario guarda 5 mientras tanto
    rpsCount($s, 3); // el cajero guarda 3 con la pantalla vieja

    expect($screenRows[0]['returned_quantity'])->toBe(0.0)
        ->and((float) ProductReturn::latest('id')->first()->quantity)->toBe(-2.0)
        ->and(rpsStock($s))->toBe(103.0);
});

// ❌ S17 — si el inventario falla, no queda nada a medias
it('rolls everything back when the inventory movement fails', function () {
    $s = rpsScenario(stock: 0);
    rpsCount($s, 5);                         // stock 0 → 5
    FinishedProductInventory::whereKey($s->inventory->id)->update(['stock' => 0]);   // el stock se usó en otra cosa

    expect(fn () => rpsCount($s, 3))         // requiere SALIDA 2
        ->toThrow(RemainingProductCountException::class, 'No se pudo actualizar el inventario. No se guardó ningún cambio.');
    expect((float) $s->detail->fresh()->returned_quantity)->toBe(5.0)
        ->and(ProductReturn::count())->toBe(1);
});

// ✅ Listado sin montos y con máximo devolvible
it('lists assignment rows in units only', function () {
    $s = rpsScenario();

    $rows = $s->service->listForEmployee($s->employee->id, $s->date);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['remaining'])->toBe(7.0)
        ->and($rows[0]['max_returnable'])->toBe(7.0)
        ->and(array_keys($rows[0]))->not->toContain('shortage_cash')
        ->and(array_keys($rows[0]))->not->toContain('shortage_cash_unit_price');
});

it('returns no rows when the employee has no assignment on the date', function () {
    $s = rpsScenario();

    expect($s->service->listForEmployee($s->employee->id, now()->addDay()->toDateString()))->toBe([]);
});
