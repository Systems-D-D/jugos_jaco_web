<?php

use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\TypePrice;
use App\Models\User;
use App\Models\DailySalesReconciliation;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Livewire\Reconciliations\CreateReconciliation;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id]);
    $this->user = User::factory()->create(['employee_id' => $this->employee->id]);
    
    $this->product = Product::factory()->create(['is_active' => true]);
    
    $this->typePrice = TypePrice::factory()->create(['name' => 'Precio Publico']);
    $this->typePrice2 = TypePrice::factory()->create(['name' => 'Precio Mayorista']);
    
    $this->productUnit = ProductUnit::factory()->create([
        'product_id' => $this->product->id,
        'is_base_unit' => true,
        'is_active' => true,
    ]);
    
    $this->productPrice = ProductPrice::factory()->create([
        'type_price_id' => $this->typePrice->id,
        'product_id' => $this->product->id,
        'product_unit_id' => $this->productUnit->id,
        'price' => 25.00,
    ]);
    
    $this->productPrice2 = ProductPrice::factory()->create([
        'type_price_id' => $this->typePrice2->id,
        'product_id' => $this->product->id,
        'product_unit_id' => $this->productUnit->id,
        'price' => 20.00,
    ]);
    
    $this->assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $this->employee->id,
        'date' => now(),
    ]);
    
    $this->detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $this->assignedProduct->id,
        'product_id' => $this->product->id,
        'quantity' => 100,
        'sale_quantity' => 70,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
        'returned_quantity' => 0,
    ]);
});

// Happy path
it('calculates product shortage cash correctly', function () {
    // remaining = 100 - 70 - 0 - 0 - 0 = 30
    // shortage_cash = 30 * 25.00 = 750.00
    
    $this->actingAs($this->user);
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->assertSet('remaining_products', function ($products) {
            return count($products) === 1 && $products[0]['remaining'] == 30;
        })
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('product_shortage_total', 750.00)
        ->assertSet('remaining_products.0.shortage_cash', 750.00)
        ->assertSet('remaining_products.0.shortage_cash_unit_price', 25.00);
});

// TypePrice change recalculates
it('recalculates when type_price changes', function () {
    $this->actingAs($this->user);
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('product_shortage_total', 750.00)
        ->set('type_price_id', $this->typePrice2->id)
        ->assertSet('product_shortage_total', 600.00);
});

// Total included in expected cash
it('adds shortage total to expected cash', function () {
    $this->actingAs($this->user);
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('total_cash_expected', 750.00);
});

// Persistence
it('persists product_shortage_total and type_price_id on save', function () {
    $this->actingAs($this->user);
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->set('cash_received', 1000.00)
        ->call('initializeReconciliation')
        ->call('saveReconciliation');
    
    $reconciliation = DailySalesReconciliation::latest()->first();
    
    expect($reconciliation->product_shortage_total)->toEqual(750.00);
    expect($reconciliation->type_price_id)->toEqual($this->typePrice->id);
});

// Edge case: no type price selected
it('sets shortage to zero when no type_price selected', function () {
    $this->actingAs($this->user);
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->assertSet('product_shortage_total', 0.0)
        ->set('type_price_id', null)
        ->assertSet('product_shortage_total', 0.0);
});

// Edge case: no remaining products
it('sets shortage to zero when no products remaining', function () {
    $this->detail->update([
        'sale_quantity' => 100,
        'returned_quantity' => 0,
    ]);
    
    $this->actingAs($this->user);
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('product_shortage_total', 0.0);
});

// Shows shortage info in model
it('stores product shortage data in reconciliation model', function () {
    $reconciliation = DailySalesReconciliation::factory()->create([
        'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id,
        'reconciliation_date' => now(),
        'product_shortage_total' => 750.00,
        'type_price_id' => $this->typePrice->id,
        'status' => 'completed',
    ]);

    expect($reconciliation->product_shortage_total)->toEqual(750.00);
    expect($reconciliation->type_price_id)->toEqual($this->typePrice->id);
    expect($reconciliation->typePrice->name)->toEqual('Precio Publico');
});

// Edge case: null type_price_id in model
it('handles null type_price_id gracefully', function () {
    $reconciliation = DailySalesReconciliation::factory()->create([
        'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id,
        'reconciliation_date' => now(),
        'product_shortage_total' => 0,
        'type_price_id' => null,
        'status' => 'completed',
    ]);

    expect($reconciliation->product_shortage_total)->toEqual(0.00);
    expect($reconciliation->type_price_id)->toBeNull();
    expect($reconciliation->typePrice)->toBeNull();
});

// Closure validation
it('blocks closure when remaining products exist and no type price is selected', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('cash_received', 1000.00)
        ->call('initializeReconciliation')
        ->call('saveReconciliation')
        ->assertSee('Debe seleccionar un precio de lista porque existen productos sobrantes.');

    $reconciliation = DailySalesReconciliation::latest()->first();
    expect($reconciliation->status->value)->toBe('pending');
});

it('allows closure when remaining products exist and a type price is selected', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->set('cash_received', 1000.00)
        ->call('initializeReconciliation')
        ->call('saveReconciliation')
        ->assertSessionHas('success', 'Cuadre guardado correctamente');

    $reconciliation = DailySalesReconciliation::latest()->first();
    expect($reconciliation->status->value)->toBe('completed');
    expect($reconciliation->type_price_id)->toEqual($this->typePrice->id);
    expect($reconciliation->product_shortage_total)->toEqual(750.00);
});

it('allows closure when no remaining products exist and no type price is selected', function () {
    // Remove all assigned product details so the remaining_products array is truly empty.
    $this->assignedProduct->details()->delete();

    $this->actingAs($this->user);

    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('cash_received', 1000.00)
        ->call('initializeReconciliation')
        ->call('saveReconciliation')
        ->assertSessionHas('success', 'Cuadre guardado correctamente');

    $reconciliation = DailySalesReconciliation::latest()->first();
    expect($reconciliation->status->value)->toBe('completed');
    expect($reconciliation->type_price_id)->toBeNull();
});

it('renders incremental row numbers in the sales table instead of sale ids', function () {
    $this->actingAs($this->user);

    // Create two sales for the reconciled employee with IDs far from the row
    // indices (1, 2) so assertDontSee is not fooled by other rendered numbers.
    \App\Models\Sale::unguard();
    $sale1 = \App\Models\Sale::factory()->create([
        'id' => 999001,
        'employee_id' => $this->employee->id,
        'sale_date' => now(),
        'payment_term' => \App\Enums\PaymentTermEnum::CASH,
        'payment_method' => \App\Enums\PaymentTypeEnum::CASH,
    ]);
    $sale2 = \App\Models\Sale::factory()->create([
        'id' => 999002,
        'employee_id' => $this->employee->id,
        'sale_date' => now(),
        'payment_term' => \App\Enums\PaymentTermEnum::CASH,
        'payment_method' => \App\Enums\PaymentTypeEnum::CASH,
    ]);
    \App\Models\Sale::reguard();

    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->assertSeeHtml('<span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">#</span>')
        ->assertSeeInOrder(['1', '2'])
        ->assertDontSee((string) $sale1->id)
        ->assertDontSee((string) $sale2->id);
});
