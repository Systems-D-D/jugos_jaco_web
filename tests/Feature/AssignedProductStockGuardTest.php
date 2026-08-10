<?php

use App\Models\AssignedProduct;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AssignedProductMovementService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Regresión: el sobrante mostrado al vendedor quedaba por debajo del físico
 * porque la venta validaba contra `quantity` y no contra el sobrante real
 * (quantity - vendido - devuelto - cambios - regalías).
 */

function makeAssignedDetail(array $overrides = []): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $client = Client::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);

    $detail = DetailAssignedProduct::factory()->create(array_merge([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 80,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ], $overrides));

    return compact('employee', 'user', 'product', 'client', 'assignedProduct', 'detail');
}

function saleLine(int $productId, float $quantity, ?string $movementType = null): array
{
    return [
        'origin' => 'api',
        'product_id' => $productId,
        'name' => 'Jugo Naranja',
        'type_price_id' => DB::table('types_prices')->insertGetId(['name' => 'Test Price']),
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

function saleHeader(Employee $employee, Client $client): array
{
    return [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 0,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];
}

// --- Fix 1: la venta debe validar contra el sobrante, no contra lo asignado ---

it('rejects a sale that exceeds the remaining stock once royalties were registered', function () {
    // Escenario real observado: 80 asignados, 6 salieron como regalía → sólo 74 vendibles.
    ['user' => $user, 'employee' => $employee, 'client' => $client,
     'product' => $product, 'detail' => $detail] = makeAssignedDetail(['royalties_quantity' => 6]);

    $this->actingAs($user);

    $service = app(SaleService::class);

    expect(fn () => $service->createSale(
        saleHeader($employee, $client),
        [saleLine($product->id, 80)],
    ))->toThrow(Exception::class, 'excede el sobrante disponible');

    // Antes del fix esto grababa sale_quantity = 80 y dejaba stock = -6
    expect((float) $detail->fresh()->sale_quantity)->toBe(0.0);
    expect(Sale::count())->toBe(0);
});

it('rejects a sale that exceeds the remaining stock once changes were registered', function () {
    ['user' => $user, 'employee' => $employee, 'client' => $client,
     'product' => $product, 'detail' => $detail] = makeAssignedDetail(['changes_quantity' => 10]);

    $this->actingAs($user);

    expect(fn () => app(SaleService::class)->createSale(
        saleHeader($employee, $client),
        [saleLine($product->id, 75)],
    ))->toThrow(Exception::class, 'excede el sobrante disponible');

    expect((float) $detail->fresh()->sale_quantity)->toBe(0.0);
});

it('rejects a sale that exceeds the remaining stock once returns were registered', function () {
    ['user' => $user, 'employee' => $employee, 'client' => $client,
     'product' => $product] = makeAssignedDetail(['returned_quantity' => 30]);

    $this->actingAs($user);

    expect(fn () => app(SaleService::class)->createSale(
        saleHeader($employee, $client),
        [saleLine($product->id, 51)],
    ))->toThrow(Exception::class, 'excede el sobrante disponible');
});

it('allows a sale for exactly the remaining stock after royalties', function () {
    ['user' => $user, 'employee' => $employee, 'client' => $client,
     'product' => $product, 'detail' => $detail] = makeAssignedDetail(['royalties_quantity' => 6]);

    $this->actingAs($user);

    $sale = app(SaleService::class)->createSale(
        saleHeader($employee, $client),
        [saleLine($product->id, 74)],
    );

    expect($sale->details)->toHaveCount(1);

    $fresh = $detail->fresh();
    expect((float) $fresh->sale_quantity)->toBe(74.0);
    expect((float) $fresh->stock)->toBe(0.0);
});

it('never leaves negative stock when a royalty and a sale line ship in the same payload', function () {
    ['user' => $user, 'employee' => $employee, 'client' => $client,
     'product' => $product, 'detail' => $detail] = makeAssignedDetail();

    $this->actingAs($user);

    // 80 asignados: no se pueden vender 80 y además regalar 6.
    expect(fn () => app(SaleService::class)->createSale(
        saleHeader($employee, $client),
        [
            saleLine($product->id, 6, 'royalty'),
            saleLine($product->id, 80),
        ],
    ))->toThrow(Exception::class);

    // Todo el createSale es transaccional: ni el movimiento ni la venta quedan grabados
    $fresh = $detail->fresh();
    expect((float) $fresh->royalties_quantity)->toBe(0.0);
    expect((float) $fresh->sale_quantity)->toBe(0.0);
    expect((float) $fresh->stock)->toBe(80.0);
    expect(Sale::count())->toBe(0);
});

// --- Fix 2: el resultado no debe depender del orden del payload ---

it('produces the same result regardless of the order of sale and movement lines', function () {
    $run = function (array $lines) {
        ['user' => $user, 'employee' => $employee, 'client' => $client,
         'product' => $product, 'detail' => $detail] = makeAssignedDetail();

        $this->actingAs($user);

        app(SaleService::class)->createSale(
            saleHeader($employee, $client),
            array_map(fn ($line) => saleLine($product->id, $line[0], $line[1]), $lines),
        );

        $fresh = $detail->fresh();

        return [
            'sale_quantity' => (float) $fresh->sale_quantity,
            'royalties_quantity' => (float) $fresh->royalties_quantity,
            'stock' => (float) $fresh->stock,
        ];
    };

    $movementFirst = $run([[6, 'royalty'], [70, null]]);
    $saleFirst = $run([[70, null], [6, 'royalty']]);

    expect($movementFirst)->toBe($saleFirst)
        ->and($saleFirst)->toBe([
            'sale_quantity' => 70.0,
            'royalties_quantity' => 6.0,
            'stock' => 4.0,
        ]);
});

it('rejects the whole ticket in both orders when sale plus royalty exceed the stock', function () {
    $attempt = function (array $lines) {
        ['user' => $user, 'employee' => $employee, 'client' => $client,
         'product' => $product, 'detail' => $detail] = makeAssignedDetail();

        $this->actingAs($user);

        try {
            app(SaleService::class)->createSale(
                saleHeader($employee, $client),
                array_map(fn ($line) => saleLine($product->id, $line[0], $line[1]), $lines),
            );

            return 'no-throw';
        } catch (Exception $e) {
            $fresh = $detail->fresh();

            return [
                'sale_quantity' => (float) $fresh->sale_quantity,
                'royalties_quantity' => (float) $fresh->royalties_quantity,
            ];
        }
    };

    expect($attempt([[6, 'royalty'], [78, null]]))
        ->toBe(['sale_quantity' => 0.0, 'royalties_quantity' => 0.0])
        ->and($attempt([[78, null], [6, 'royalty']]))
        ->toBe(['sale_quantity' => 0.0, 'royalties_quantity' => 0.0]);
});

it('accumulates sale quantity across consecutive sales without exceeding the remaining stock', function () {
    ['user' => $user, 'employee' => $employee, 'client' => $client,
     'product' => $product, 'detail' => $detail] = makeAssignedDetail(['royalties_quantity' => 6]);

    $this->actingAs($user);
    $service = app(SaleService::class);

    // Clientes distintos por venta: ClientVisitService sólo admite una visita
    // por cliente y día, lo que es ajeno a este caso de regresión.
    $service->createSale(saleHeader($employee, $client), [saleLine($product->id, 40)]);
    $service->createSale(saleHeader($employee, Client::factory()->create()), [saleLine($product->id, 34)]);

    expect((float) $detail->fresh()->sale_quantity)->toBe(74.0);

    expect(fn () => $service->createSale(
        saleHeader($employee, Client::factory()->create()),
        [saleLine($product->id, 1)],
    ))->toThrow(Exception::class, 'excede el sobrante disponible');

    expect((float) $detail->fresh()->stock)->toBe(0.0);
});

// --- Fix 3: reversión del acumulador al eliminar un movimiento ---

it('reverts the accumulator when a movement is deleted', function () {
    ['user' => $user, 'detail' => $detail] = makeAssignedDetail();
    $this->actingAs($user);

    $service = app(AssignedProductMovementService::class);
    $movement = $service->createMovement($detail->id, 'royalty', 6);

    expect((float) $detail->fresh()->royalties_quantity)->toBe(6.0);

    $service->deleteMovement($movement->id);

    $fresh = $detail->fresh();
    expect((float) $fresh->royalties_quantity)->toBe(0.0);
    expect((float) $fresh->stock)->toBe(80.0);
});

it('never drives an accumulator below zero when reverting a movement', function () {
    ['user' => $user, 'detail' => $detail] = makeAssignedDetail();
    $this->actingAs($user);

    $service = app(AssignedProductMovementService::class);
    $movement = $service->createMovement($detail->id, 'change', 5);

    // Simula un acumulador ya desincronizado (drift histórico) antes de revertir
    $detail->update(['changes_quantity' => 2]);

    $service->deleteMovement($movement->id);

    expect((float) $detail->fresh()->changes_quantity)->toBe(0.0);
});

// --- Fix 7: los casts decimales deben estar activos ---

it('casts the assigned product detail accumulators as decimals', function () {
    ['detail' => $detail] = makeAssignedDetail([
        'quantity' => 80,
        'sale_quantity' => 10,
        'royalties_quantity' => 6,
    ]);

    $fresh = $detail->fresh();

    expect($fresh->getCasts())->toHaveKey('quantity')
        ->and($fresh->quantity)->toEqual('80.00')
        ->and($fresh->sale_quantity)->toEqual('10.00')
        ->and($fresh->royalties_quantity)->toEqual('6.00')
        ->and((float) $fresh->stock)->toBe(64.0);
});
