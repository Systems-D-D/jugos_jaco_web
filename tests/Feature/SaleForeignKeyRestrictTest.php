<?php

use App\Enums\PaymentTermEnum;
use App\Models\AccountReceivable;
use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TypePrice;
use App\Models\User;
use App\Services\AssignedProductMovementService;
use App\Services\SaleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Fase 7 de docs/devflow/specs/2026-08-10-sale-deletion-analysis.md (§10.6):
 * account_receivables.sales_id y assigned_product_movements.sale_id pasan
 * de SET NULL a RESTRICT. Una venta nunca se borra físicamente (fase 0), así
 * que un intento de DELETE directo sobre `sales` ahora debe fallar
 * explícitamente en la base de datos en vez de dejar huérfanos en silencio.
 */

it('blocks deleting a sale that has an account receivable', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $client = Client::factory()->create();

    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'payment_term' => PaymentTermEnum::CREDIT,
    ]);

    AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC de prueba',
        'total_amount' => 100,
        'remaining_balance' => 100,
    ]);

    expect(fn () => $sale->delete())->toThrow(QueryException::class);

    // La venta y la CxC siguen intactas: RESTRICT abortó el DELETE.
    expect(Sale::find($sale->id))->not->toBeNull();
    expect(AccountReceivable::where('sales_id', $sale->id)->exists())->toBeTrue();
});

it('blocks deleting a sale that has an assigned product movement', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $product = Product::factory()->create(['is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
    ]);

    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
    ]);

    Auth::login($user);
    $movement = app(AssignedProductMovementService::class)
        ->createMovement($detail->id, 'royalty', 3, 'Regalía de prueba', $sale->id);

    expect(fn () => $sale->delete())->toThrow(QueryException::class);

    expect(Sale::find($sale->id))->not->toBeNull();
    expect(AssignedProductMovement::find($movement->id))->not->toBeNull();
});

it('still allows deleting an assigned_product_movement row on its own (child deletion is unaffected)', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $product = Product::factory()->create(['is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
    ]);

    Auth::login($user);
    $movement = app(AssignedProductMovementService::class)
        ->createMovement($detail->id, 'change', 2);

    // Borrar el movimiento (el hijo) nunca estuvo restringido: RESTRICT sólo
    // gobierna qué pasa cuando se borra la venta (el padre).
    app(AssignedProductMovementService::class)->deleteMovement($movement->id);

    expect(AssignedProductMovement::find($movement->id))->toBeNull();
});

it('a sale can still be cancelled (updated, not deleted) when it has an account receivable and assigned product movements', function () {
    // Confirma que RESTRICT no interfiere con la fase 4: la anulación nunca
    // ejecuta un DELETE sobre `sales`, sólo UPDATE + DELETE de las filas
    // hijas (movements), que sigue permitido.
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 80,
    ]);

    Auth::login($user);

    $sale = app(SaleService::class)->createSale(
        [
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'sale_date' => now()->toDateString(),
            'cash_amount' => 0,
            'payment_method' => 'cash',
            'payment_term' => PaymentTermEnum::CREDIT->value,
            'branch_id' => $branch->id,
        ],
        [[
            'origin' => 'api',
            'product_id' => $product->id,
            'name' => $product->name,
            'type_price_id' => TypePrice::factory()->create()->id,
            'unit_name' => 'Unidad',
            'quantity' => 10,
            'unit_price_without_tax' => 10,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
        ], [
            'origin' => 'api',
            'product_id' => $product->id,
            'name' => $product->name,
            'quantity' => 2,
            'unit_price_without_tax' => 10,
            'unit_tax_amount' => 0,
            'line_subtotal' => 20,
            'line_tax_amount' => 0,
            'line_total' => 20,
            'movement_type' => 'royalty',
        ]],
    );

    $cancelled = app(\App\Services\SaleCancellationService::class)->cancel($sale->fresh(), 'Prueba RESTRICT');

    expect($cancelled->status)->toBe(\App\Enums\SaleStatusEnum::CANCELLED);
    expect(Sale::find($sale->id))->not->toBeNull();
});
