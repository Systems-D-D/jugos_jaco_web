<?php

use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use App\Models\AccountReceivable;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TypePrice;
use App\Models\User;
use App\Services\SaleCancellationService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Fase 6 de docs/devflow/specs/2026-08-10-sale-deletion-analysis.md:
 * DELETE /api/sales/{id} (app móvil). Pertenencia (§9: sólo el vendedor
 * dueño), idempotencia HTTP (§8: 200 en reintento) y aviso de devolución
 * del abono (R6).
 */

function apiCancelScenario(array $headerOverrides = []): array
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $seller = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 80,
        'sale_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
        'returned_quantity' => 0,
    ]);

    Auth::login($seller);

    $sale = app(SaleService::class)->createSale(
        array_merge([
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'sale_date' => now()->toDateString(),
            'cash_amount' => 100,
            'payment_method' => 'cash',
            'payment_term' => PaymentTermEnum::CASH->value,
            'branch_id' => $branch->id,
        ], $headerOverrides),
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
        ]],
    );

    return compact('branch', 'employee', 'seller', 'client', 'product', 'detail', 'sale');
}

it('cancels a sale via DELETE and reverts sale_quantity', function () {
    $scenario = apiCancelScenario();

    $this->actingAs($scenario['seller'], 'sanctum');

    $response = $this->deleteJson("/api/sales/{$scenario['sale']->id}")
        ->assertOk();

    expect($response->json('data.status'))->toBe('cancelled');
    expect($scenario['sale']->fresh()->status)->toBe(SaleStatusEnum::CANCELLED);
    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(0.0);
});

it('accepts an optional reason and persists it', function () {
    $scenario = apiCancelScenario();

    $this->actingAs($scenario['seller'], 'sanctum');

    $this->deleteJson("/api/sales/{$scenario['sale']->id}", ['reason' => 'Error de captura'])
        ->assertOk();

    expect($scenario['sale']->fresh()->cancellation_reason)->toBe('Error de captura');
});

it('cancels without a reason: it is optional on this channel', function () {
    $scenario = apiCancelScenario();

    $this->actingAs($scenario['seller'], 'sanctum');

    $this->deleteJson("/api/sales/{$scenario['sale']->id}")
        ->assertOk();

    expect($scenario['sale']->fresh()->status)->toBe(SaleStatusEnum::CANCELLED);
});

it('returns 404 for a sale that does not exist', function () {
    $seller = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($seller, 'sanctum');

    $this->deleteJson('/api/sales/999999')->assertStatus(404);
});

it('returns 403 when the sale belongs to another salesperson', function () {
    $scenario = apiCancelScenario();

    $intruder = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->actingAs($intruder, 'sanctum');

    $this->deleteJson("/api/sales/{$scenario['sale']->id}")->assertStatus(403);

    expect($scenario['sale']->fresh()->status)->not->toBe(SaleStatusEnum::CANCELLED);
    expect((float) $scenario['detail']->fresh()->sale_quantity)->toBe(10.0);
});

it('is idempotent over HTTP: retrying the DELETE on an already cancelled sale returns 200, not an error', function () {
    $scenario = apiCancelScenario();
    $this->actingAs($scenario['seller'], 'sanctum');

    $this->deleteJson("/api/sales/{$scenario['sale']->id}")->assertOk();

    // Reintento de la cola offline del móvil: debe seguir respondiendo 200.
    $this->deleteJson("/api/sales/{$scenario['sale']->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('maps a rejected precondition (R2: reconciliation exists) to 422 with the exact service message', function () {
    $scenario = apiCancelScenario();

    \App\Models\DailySalesReconciliation::factory()->create([
        'employee_id' => $scenario['employee']->id,
        'branch_id' => $scenario['branch']->id,
        'reconciliation_date' => now(),
    ]);

    $this->actingAs($scenario['seller'], 'sanctum');

    $this->deleteJson("/api/sales/{$scenario['sale']->id}")
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'El día ya tiene cuadre; no se puede anular la venta.']);
});

it('maps a rejected precondition (R5: payments already registered) to 422', function () {
    $scenario = apiCancelScenario([
        'cash_amount' => 0,
        'payment_term' => PaymentTermEnum::CREDIT->value,
    ]);

    $accountReceivable = $scenario['sale']->fresh()->accountReceivable;
    Payment::create([
        'model_type' => AccountReceivable::class,
        'model_id' => $accountReceivable->id,
        'amount' => 10,
        'balance_after_payment' => $accountReceivable->remaining_balance - 10,
        'payment_date' => now(),
        'payment_method' => 'cash',
    ]);

    $this->actingAs($scenario['seller'], 'sanctum');

    $this->deleteJson("/api/sales/{$scenario['sale']->id}")
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'La venta tiene pagos registrados sobre su cuenta por cobrar; no se puede anular.']);

    expect($accountReceivable->fresh()->status)->not->toBe(\App\Enums\AccountReceivableStatusEnum::CANCELLED);
});

// --- R6: aviso de devolución del abono inicial ---

it('includes cash_to_return and the refund notice for a credit sale with an initial cash_amount', function () {
    $scenario = apiCancelScenario([
        'cash_amount' => 35,
        'payment_term' => PaymentTermEnum::CREDIT->value,
    ]);

    $this->actingAs($scenario['seller'], 'sanctum');

    $response = $this->deleteJson("/api/sales/{$scenario['sale']->id}")->assertOk();

    expect((float) $response->json('data.cash_to_return'))->toBe(35.0)
        ->and($response->json('message'))->toContain('35.00');
});

it('does not include cash_to_return for a cash sale', function () {
    $scenario = apiCancelScenario();

    $this->actingAs($scenario['seller'], 'sanctum');

    $response = $this->deleteJson("/api/sales/{$scenario['sale']->id}")->assertOk();

    expect($response->json('data.cash_to_return'))->toBeNull();
});
