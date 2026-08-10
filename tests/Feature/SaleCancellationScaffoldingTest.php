<?php

use App\Enums\AccountReceivableStatusEnum;
use App\Enums\SaleStatusEnum;
use App\Livewire\Reconciliations\CreateReconciliation;
use App\Livewire\Sales\CreateSale;
use App\Models\AccountReceivable;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TypePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Regresión de las fases 0-2 del análisis de anulación de ventas
 * (docs/devflow/specs/2026-08-10-sale-deletion-analysis.md):
 *
 *  - Fase 0: se retira el borrado físico de Filament.
 *  - Fase 1: la fecha de venta de la web ya no es editable por el cliente.
 *  - Fase 2: scopes que excluyen ventas/CxC anuladas de sumas y reportes.
 *
 * Todavía no existe el servicio de anulación; estos tests fijan el terreno
 * sobre el que se va a construir.
 */

// --- Fase 2: Sale::scopeNotCancelled ---

it('excludes cancelled sales from the notCancelled scope', function () {
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();

    $active = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'status' => SaleStatusEnum::PAID,
    ]);
    $cancelled = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'status' => SaleStatusEnum::CANCELLED,
    ]);

    $ids = Sale::notCancelled()->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($cancelled->id);
});

it('excludes cancelled sales from the API sales listing of the day', function () {
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();

    $active = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'sale_date' => now(),
        'status' => SaleStatusEnum::PAID,
    ]);
    Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'sale_date' => now(),
        'status' => SaleStatusEnum::CANCELLED,
    ]);

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/sales')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->toHaveCount(1);
});

it('excludes cancelled sales from the daily reconciliation totals', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $client = Client::factory()->create();

    Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'sale_date' => now(),
        'status' => SaleStatusEnum::PAID,
        'total_amount' => 100,
        'payment_term' => 'cash',
    ]);
    Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'sale_date' => now(),
        'status' => SaleStatusEnum::CANCELLED,
        'total_amount' => 9999,
        'payment_term' => 'cash',
    ]);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $employee->id])
        ->assertSet('sales', fn ($sales) => count($sales) === 1);
});

// --- Fase 2: AccountReceivable::scopeNotCancelled ---

it('excludes cancelled accounts receivable from the notCancelled scope', function () {
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();
    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'payment_term' => 'credit',
    ]);

    $pending = AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC pendiente',
        'total_amount' => 100,
        'remaining_balance' => 100,
        'status' => AccountReceivableStatusEnum::PENDING,
    ]);
    $cancelled = AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC cancelada',
        'total_amount' => 100,
        'remaining_balance' => 100,
        'status' => AccountReceivableStatusEnum::CANCELLED,
    ]);

    $ids = AccountReceivable::notCancelled()->pluck('id');

    expect($ids)->toContain($pending->id)
        ->and($ids)->not->toContain($cancelled->id);
});

it('excludes cancelled accounts receivable from the API listing for the employee', function () {
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'payment_term' => 'credit',
    ]);

    $pending = AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC pendiente',
        'total_amount' => 100,
        'remaining_balance' => 100,
        'status' => AccountReceivableStatusEnum::PENDING,
        'paid_at' => null,
    ]);
    AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC cancelada',
        'total_amount' => 100,
        'remaining_balance' => 100,
        'status' => AccountReceivableStatusEnum::CANCELLED,
        'paid_at' => null,
    ]);

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/account-receivable')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($pending->id)
        ->and($ids)->toHaveCount(1);
});

// --- Fase 1: la fecha de venta de la web no es editable por el cliente ---

it('forces the server date on a web sale regardless of the sale_date sent by the client', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $typePrice = TypePrice::factory()->create();
    $product = Product::factory()->create(['is_active' => true]);

    $this->actingAs($user);

    // Un componente Livewire manipulado podría enviar cualquier sale_date; el
    // servidor no debe confiar en la propiedad al persistir la venta.
    $manipulatedDate = now()->subMonth()->format('Y-m-d');

    Livewire::test(CreateSale::class)
        ->set('client_id', $client->id)
        ->set('employee_id', $employee->id)
        ->set('sale_date', $manipulatedDate)
        ->set('payment_method', 'efectivo')
        ->set('payment_term', 'cash')
        ->set('amount_paid', 100)
        ->set('products', [[
            'inventory_id' => null,
            'product_id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'type_price_id' => $typePrice->id,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'price_include_tax' => false,
            'price_with_tax' => 100,
            'quantity' => 1,
            'base_quantity' => 1,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'tax_rate' => 0,
            'tax_category_name' => null,
            'tax_category_id' => null,
            'unit_abbreviation' => 'u',
            'unit_name' => 'Unidad',
            'stock' => 10,
            'discount_percentage' => 0,
            'discount_amount' => 0,
        ]])
        ->call('updateSaleTotals')
        ->call('save');

    $sale = Sale::where('client_id', $client->id)->firstOrFail();

    expect($sale->sale_date->toDateString())->toBe(now()->toDateString())
        ->and($sale->sale_date->toDateString())->not->toBe($manipulatedDate);
});

// --- Fase 0: el borrado físico ya no está disponible desde Filament ---

it('sale edit page no longer offers a physical delete action', function () {
    $reflection = new ReflectionMethod(\App\Filament\Resources\SaleResource\Pages\EditSale::class, 'getHeaderActions');
    $reflection->setAccessible(true);

    $employee = Employee::factory()->create();
    $client = Client::factory()->create();
    $sale = Sale::factory()->create(['employee_id' => $employee->id, 'client_id' => $client->id]);

    $page = new \App\Filament\Resources\SaleResource\Pages\EditSale();
    $page->record = $sale;

    $actions = $reflection->invoke($page);
    $names = collect($actions)->map(fn ($action) => $action->getName());

    expect($names)->not->toContain('delete');
});
