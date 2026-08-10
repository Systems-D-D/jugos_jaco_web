<?php

use App\Enums\PaymentTermEnum;
use App\Enums\SaleChannelEnum;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresión de la fase 3 del análisis de anulación de ventas
 * (docs/devflow/specs/2026-08-10-sale-deletion-analysis.md §5.1, §10): antes
 * de esta migración, branch_id y payment_reference se enviaban a
 * Sale::create() pero Eloquent los descartaba en silencio por no existir
 * como columnas ni estar en $fillable.
 */

it('persists branch_id and payment_reference on a sale created through the API flow', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();

    $this->actingAs($user);

    $sale = app(SaleService::class)->createSale(
        [
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'sale_date' => now()->toDateString(),
            'cash_amount' => 0,
            'payment_method' => 'deposit',
            'payment_term' => PaymentTermEnum::CASH->value,
            'branch_id' => $branch->id,
            'payment_reference' => 'DEP-00123',
        ],
        [],
    );

    $fresh = $sale->fresh();

    expect($fresh->branch_id)->toBe($branch->id)
        ->and($fresh->payment_reference)->toBe('DEP-00123')
        ->and($fresh->branch->id)->toBe($branch->id);
});

it('exposes the cancellation audit columns and the cancelledBy relation', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $client = Client::factory()->create();
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);

    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
    ]);

    $sale->update([
        'cancelled_at' => now(),
        'cancelled_by' => $admin->id,
        'cancellation_reason' => 'Prueba de regresión de esquema',
    ]);

    $fresh = $sale->fresh();

    expect($fresh->cancelled_at)->not->toBeNull()
        ->and($fresh->cancellation_reason)->toBe('Prueba de regresión de esquema')
        ->and($fresh->cancelledBy->id)->toBe($admin->id);
});

it('casts the channel column to SaleChannelEnum', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $client = Client::factory()->create();

    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'branch_id' => $branch->id,
        'channel' => SaleChannelEnum::WEB,
    ]);

    expect($sale->fresh()->channel)->toBe(SaleChannelEnum::WEB);
});
