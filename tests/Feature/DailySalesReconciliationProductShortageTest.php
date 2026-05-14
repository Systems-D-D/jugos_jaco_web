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
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('product_shortage_total', 750.00)
        ->set('type_price_id', $this->typePrice2->id)
        ->assertSet('product_shortage_total', 600.00);
});

// Total included in expected cash
it('adds shortage total to expected cash', function () {
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('total_cash_expected', 750.00);
});

// Persistence
it('persists product_shortage_total and type_price_id on save', function () {
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
    
    Livewire::test(CreateReconciliation::class, ['employee_id' => $this->employee->id])
        ->set('type_price_id', $this->typePrice->id)
        ->assertSet('product_shortage_total', 0.0);
});

// Shows shortage info in view page
it('shows shortage info in view page', function () {
    $reconciliation = DailySalesReconciliation::factory()->create([
        'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id,
        'reconciliation_date' => now(),
        'product_shortage_total' => 750.00,
        'type_price_id' => $this->typePrice->id,
        'status' => 'completed',
    ]);

    $this->actingAs($this->user);
    
    $response = $this->get(
        \App\Filament\Resources\DailySalesReconciliationResource::getUrl('view', ['record' => $reconciliation])
    );
    
    $response->assertStatus(200);
    $response->assertSee('750.00');
    $response->assertSee('Precio Publico');
});

// Edge case: no type_price selected in view page
it('shows placeholder when no type_price selected', function () {
    $reconciliation = DailySalesReconciliation::factory()->create([
        'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id,
        'reconciliation_date' => now(),
        'product_shortage_total' => 0,
        'type_price_id' => null,
        'status' => 'completed',
    ]);

    $this->actingAs($this->user);
    
    $response = $this->get(
        \App\Filament\Resources\DailySalesReconciliationResource::getUrl('view', ['record' => $reconciliation])
    );
    
    $response->assertStatus(200);
    $response->assertSee('L 0.00');
});
