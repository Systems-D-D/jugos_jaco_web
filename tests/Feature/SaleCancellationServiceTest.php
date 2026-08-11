<?php

use App\Enums\AccountReceivableStatusEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Exceptions\SaleCancellationException;
use App\Models\AccountReceivable;
use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Client;
use App\Models\DailySalesReconciliation;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\ManagementInventory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\TaxCategory;
use App\Models\TypePrice;
use App\Models\Unit;
use App\Models\User;
use App\Services\SaleCancellationService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Fase 4 de docs/devflow/specs/2026-08-10-sale-deletion-analysis.md:
 * SaleCancellationService. Cubre la matriz de tests del §13 para el caso app
 * (§4), el caso web (§5), las precondiciones R1-R5 (§7) e idempotencia (§8).
 */

function cancellationSaleHeader(Employee $employee, Client $client, array $overrides = []): array
{
    return array_merge([
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 100,
        'payment_method' => 'cash',
        'payment_term' => PaymentTermEnum::CASH->value,
        'branch_id' => $employee->branch_id,
    ], $overrides);
}

function cancellationSaleLine(int $productId, string $name, float $quantity, ?string $movementType = null): array
{
    return [
        'origin' => 'api',
        'product_id' => $productId,
        'name' => $name,
        'type_price_id' => TypePrice::factory()->create()->id,
        'unit_name' => 'Unidad',
        'quantity' => $quantity,
        'unit_price_without_tax' => 10,
        'unit_tax_amount' => 0,
        'line_subtotal' => 10 * $quantity,
        'line_tax_amount' => 0,
        'line_total' => 10 * $quantity,
        'movement_type' => $movementType,
    ];
}

/**
 * Escenario "venta desde la app": empleado con AssignedProduct/detalle hoy,
 * usuario autenticado con ese employee_id (igual que SaleController).
 */
function makeAppSaleScenario(array $detailOverrides = []): array
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);

    $detail = DetailAssignedProduct::factory()->create(array_merge([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 80,
        'sale_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
        'returned_quantity' => 0,
    ], $detailOverrides));

    Auth::login($user);

    return compact('branch', 'employee', 'user', 'client', 'product', 'assignedProduct', 'detail');
}

/**
 * Escenario "venta desde la web": producto con inventario en la sucursal,
 * sin ninguna asignación para el empleado (un cajero no tiene productos
 * asignados).
 */
function makeWebSaleScenario(): array
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $category = Category::factory()->create(['name' => 'Test ' . uniqid()]);
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true, 'name' => 'Jugo Naranja']);
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
    ProductPrice::factory()->create([
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

    return compact('branch', 'employee', 'user', 'client', 'product', 'typePrice', 'inventory');
}

function createWebSale(array $scenario, float $quantity = 2): Sale
{
    return app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [[
            'product_id' => $scenario['product']->id,
            'name' => $scenario['product']->name,
            'type_price_id' => $scenario['typePrice']->id,
            'unit_name' => 'Unidad',
            'quantity' => $quantity,
            'base_quantity' => $quantity,
            'unit_price_without_tax' => 10,
            'unit_tax_amount' => 0,
            'line_subtotal' => 10 * $quantity,
            'line_tax_amount' => 0,
            'line_total' => 10 * $quantity,
            'inventory_id' => $scenario['inventory']->id,
        ]],
    );
}

// --- Caso app (§4) ---

it('reverts sale_quantity to the previous value and restores stock', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(10.0);

    $cancelled = app(SaleCancellationService::class)->cancel($sale->fresh());

    expect($cancelled->status)->toBe(SaleStatusEnum::CANCELLED);

    $fresh = $scenario['detail']->fresh();
    expect((float) $fresh->sale_quantity)->toBe(0.0)
        ->and((float) $fresh->stock)->toBe(80.0);
});

it('reverts royalties_quantity and removes the linked assigned_product_movement', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 3, 'royalty')],
    );

    expect((float) $scenario['detail']->fresh()->royalties_quantity)->toBe(3.0);
    expect(AssignedProductMovement::where('sale_id', $sale->id)->count())->toBe(1);

    app(SaleCancellationService::class)->cancel($sale->fresh());

    $fresh = $scenario['detail']->fresh();
    expect((float) $fresh->royalties_quantity)->toBe(0.0)
        ->and((float) $fresh->stock)->toBe(80.0);
    expect(AssignedProductMovement::where('sale_id', $sale->id)->count())->toBe(0);
});

it('reverts changes_quantity the same way as royalties', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 4, 'change')],
    );

    app(SaleCancellationService::class)->cancel($sale->fresh());

    $fresh = $scenario['detail']->fresh();
    expect((float) $fresh->changes_quantity)->toBe(0.0)
        ->and((float) $fresh->stock)->toBe(80.0);
});

it('reverts both a normal line and a royalty line of the same product without double counting', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [
            cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 6, 'royalty'),
            cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 20, null),
        ],
    );

    $fresh = $scenario['detail']->fresh();
    expect((float) $fresh->sale_quantity)->toBe(20.0)
        ->and((float) $fresh->royalties_quantity)->toBe(6.0);

    app(SaleCancellationService::class)->cancel($sale->fresh());

    $fresh = $scenario['detail']->fresh();
    expect((float) $fresh->sale_quantity)->toBe(0.0)
        ->and((float) $fresh->royalties_quantity)->toBe(0.0)
        ->and((float) $fresh->stock)->toBe(80.0);
});

it('resolves the assignment by sale.employee_id and sale.sale_date when the actor is an admin without employee', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    // El admin que anula tiene SU PROPIO employee, distinto del vendedor de
    // la venta: si la reversión usara Auth::user()->employee->id en vez de
    // sale.employee_id, resolvería la asignación equivocada (o ninguna).
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    Auth::login($admin);

    $cancelled = app(SaleCancellationService::class)->cancel($sale->fresh());

    expect($cancelled->status)->toBe(SaleStatusEnum::CANCELLED)
        ->and($cancelled->cancelled_by)->toBe($admin->id);

    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(0.0);
});

it('fails the whole cancellation with a clear message when there is no evidence to revert a line', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    // Se borra la asignación del día: ya no hay evidencia de dónde vino la venta.
    $scenario['detail']->delete();
    $scenario['assignedProduct']->delete();

    expect(fn () => app(SaleCancellationService::class)->cancel($sale->fresh()))
        ->toThrow(SaleCancellationException::class, 'No se encontró evidencia');

    // Nada se revirtió a medias.
    expect($sale->fresh()->status)->not->toBe(SaleStatusEnum::CANCELLED);
});

it('floors sale_quantity at zero and logs a warning instead of going negative', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    // Simula un drift previo: alguien bajó sale_quantity por fuera de este flujo.
    $scenario['detail']->update(['sale_quantity' => 4]);

    app(SaleCancellationService::class)->cancel($sale->fresh());

    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(0.0);
});

it('never touches inventory for an app sale', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    app(SaleCancellationService::class)->cancel($sale->fresh());

    expect(ManagementInventory::where('reference_type', Sale::class)->where('reference_id', $sale->id)->count())->toBe(0);
});

// --- Caso web (§5) ---

it('restores finished_product_inventories.stock for a web sale', function () {
    $scenario = makeWebSaleScenario();
    $sale = createWebSale($scenario, 5);

    expect($scenario['inventory']->fresh()->stock)->toEqual(95);

    app(SaleCancellationService::class)->cancel($sale->fresh());

    expect((float) $scenario['inventory']->fresh()->stock)->toBe(100.0);
});

it('creates a compensating DEVOLUCION entry without deleting the original SALIDA', function () {
    $scenario = makeWebSaleScenario();
    $sale = createWebSale($scenario, 5);

    $salidaBefore = ManagementInventory::where('reference_type', Sale::class)
        ->where('reference_id', $sale->id)
        ->where('type', TypeInventoryManagementEnum::SALIDA->value)
        ->first();
    expect($salidaBefore)->not->toBeNull();

    app(SaleCancellationService::class)->cancel($sale->fresh());

    // El asiento original sigue existiendo, intacto.
    expect(ManagementInventory::find($salidaBefore->id))->not->toBeNull();

    $devolucion = ManagementInventory::where('reference_type', Sale::class)
        ->where('reference_id', $sale->id)
        ->where('type', TypeInventoryManagementEnum::DEVOLUCION->value)
        ->first();

    expect($devolucion)->not->toBeNull()
        ->and((float) $devolucion->quantity)->toBe(5.0);
});

it('never touches detail_assigned_products for a web sale', function () {
    $scenario = makeWebSaleScenario();
    $sale = createWebSale($scenario, 5);

    app(SaleCancellationService::class)->cancel($sale->fresh());

    expect(DetailAssignedProduct::where('product_id', $scenario['product']->id)->count())->toBe(0);
});

// --- Precondiciones R1-R5 (§7) ---

it('rejects cancelling a sale from a previous day (R1)', function () {
    $scenario = makeAppSaleScenario();

    $sale = Sale::factory()->create([
        'employee_id' => $scenario['employee']->id,
        'client_id' => $scenario['client']->id,
        'branch_id' => $scenario['branch']->id,
        'sale_date' => now()->subDay(),
        'created_at' => now()->subDay(),
    ]);

    expect(fn () => app(SaleCancellationService::class)->cancel($sale))
        ->toThrow(SaleCancellationException::class, 'mismo día');
});

it('rejects cancelling when the day already has a reconciliation, even pending (R2)', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    DailySalesReconciliation::factory()->create([
        'employee_id' => $scenario['employee']->id,
        'branch_id' => $scenario['branch']->id,
        'reconciliation_date' => now(),
        'status' => \App\Enums\ReconciliationStatusEnum::PENDING,
    ]);

    expect(fn () => app(SaleCancellationService::class)->cancel($sale->fresh()))
        ->toThrow(SaleCancellationException::class, 'cuadre');

    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(10.0);
});

it('cancels a cash sale even when its status is PAID (R3)', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client'], ['cash_amount' => 1000]),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    expect($sale->status)->toBe(SaleStatusEnum::PAID);

    $cancelled = app(SaleCancellationService::class)->cancel($sale->fresh());

    expect($cancelled->status)->toBe(SaleStatusEnum::CANCELLED);
});

it('cancels a credit sale without payments and marks its account receivable as CANCELLED, not deleted (R4)', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client'], [
            'cash_amount' => 0,
            'payment_term' => PaymentTermEnum::CREDIT->value,
        ]),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    $accountReceivable = $sale->fresh()->accountReceivable;
    expect($accountReceivable)->not->toBeNull()
        ->and($accountReceivable->status)->toBe(AccountReceivableStatusEnum::PENDING);

    app(SaleCancellationService::class)->cancel($sale->fresh());

    $freshAccount = $accountReceivable->fresh();
    expect($freshAccount)->not->toBeNull()
        ->and($freshAccount->status)->toBe(AccountReceivableStatusEnum::CANCELLED)
        ->and($freshAccount->cancelled_at)->not->toBeNull();
});

it('rejects cancelling a credit sale that has at least one payment registered (R5)', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client'], [
            'cash_amount' => 0,
            'payment_term' => PaymentTermEnum::CREDIT->value,
        ]),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    $accountReceivable = $sale->fresh()->accountReceivable;
    Payment::create([
        'model_type' => AccountReceivable::class,
        'model_id' => $accountReceivable->id,
        'amount' => 50,
        'balance_after_payment' => $accountReceivable->remaining_balance - 50,
        'payment_date' => now(),
        'payment_method' => 'cash',
    ]);

    expect(fn () => app(SaleCancellationService::class)->cancel($sale->fresh()))
        ->toThrow(SaleCancellationException::class, 'pagos registrados');

    expect($accountReceivable->fresh()->status)->toBe(AccountReceivableStatusEnum::PENDING);
    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(10.0);
});

it('allows cancelling a credit sale with an initial cash_amount and no payments row (R6)', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client'], [
            'cash_amount' => 30,
            'payment_term' => PaymentTermEnum::CREDIT->value,
        ]),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    expect((float) $sale->cash_amount)->toBe(30.0);
    expect(Payment::where('model_type', AccountReceivable::class)->count())->toBe(0);

    $cancelled = app(SaleCancellationService::class)->cancel($sale->fresh());

    expect($cancelled->status)->toBe(SaleStatusEnum::CANCELLED);
});

it('rejects cancelling an invoiced sale', function () {
    $scenario = makeAppSaleScenario();

    $sale = Sale::factory()->create([
        'employee_id' => $scenario['employee']->id,
        'client_id' => $scenario['client']->id,
        'branch_id' => $scenario['branch']->id,
        'sale_date' => now(),
        'created_at' => now(),
        'invoice_number' => 1,
    ]);

    expect(fn () => app(SaleCancellationService::class)->cancel($sale))
        ->toThrow(SaleCancellationException::class, 'factura');
});

// --- Idempotencia (§8) ---

it('is idempotent: cancelling an already cancelled sale is a no-op that does not throw', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client']),
        [
            cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10),
            cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 3, 'royalty'),
        ],
    );

    $service = app(SaleCancellationService::class);
    $service->cancel($sale->fresh());
    $again = $service->cancel($sale->fresh());

    expect($again->status)->toBe(SaleStatusEnum::CANCELLED);
    // No se revirtió sale_quantity dos veces (no quedó negativo ni afectado dos veces).
    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(0.0);
});

it('is idempotent for a web sale: a second cancel does not double-compensate inventory', function () {
    // El caso donde el guardia de idempotencia SÍ es indispensable: la
    // reversión de inventario vuelve a leer los mismos asientos SALIDA en
    // cada llamada (no se marcan como "ya compensados"), así que sin el
    // corte temprano por sale.isCancelled(), un segundo cancel() generaría
    // una segunda DEVOLUCION y duplicaría el stock devuelto.
    $scenario = makeWebSaleScenario();
    $sale = createWebSale($scenario, 5);

    $service = app(SaleCancellationService::class);
    $service->cancel($sale->fresh());
    $service->cancel($sale->fresh());

    expect((float) $scenario['inventory']->fresh()->stock)->toBe(100.0);
    expect(ManagementInventory::where('reference_type', Sale::class)
        ->where('reference_id', $sale->id)
        ->where('type', TypeInventoryManagementEnum::DEVOLUCION->value)
        ->count())->toBe(1);
});

it('does not partially apply changes when the reversal fails midway', function () {
    $scenario = makeAppSaleScenario();

    $sale = app(SaleService::class)->createSale(
        cancellationSaleHeader($scenario['employee'], $scenario['client'], [
            'cash_amount' => 0,
            'payment_term' => PaymentTermEnum::CREDIT->value,
        ]),
        [cancellationSaleLine($scenario['product']->id, $scenario['product']->name, 10)],
    );

    // Fuerza el fallo de la aserción de invariante: restar sale_quantity
    // sólo puede subir el stock calculado, nunca bajarlo, así que para que
    // termine negativo hace falta que OTRO acumulador (aquí returned_quantity,
    // simulando un drift que R2 debería haber evitado) ya exceda quantity
    // por sí solo, independientemente de lo que se revierta.
    $scenario['detail']->update(['returned_quantity' => 85]);

    expect(fn () => app(SaleCancellationService::class)->cancel($sale->fresh()))
        ->toThrow(SaleCancellationException::class);

    // La venta NO quedó cancelada: todo el rollback ocurrió.
    expect($sale->fresh()->status)->not->toBe(SaleStatusEnum::CANCELLED);
    expect($sale->fresh()->accountReceivable->status)->toBe(AccountReceivableStatusEnum::PENDING);
});
