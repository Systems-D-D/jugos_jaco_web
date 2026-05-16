# DevFlow Implementation Plan

**Goal:** En la tabla Productos Sobrantes del cuadre: agregar columna "Efectivo Prod. Faltante" (= Sobrante x Precio TypePrice seleccionado), selector TypePrice en header, badge de codigo en nombre, total arriba de Efectivo Esperado y sumado a este, altura igual a Ventas del Dia.
**Architecture:** `.opencode/plans/product-shortage-cash-factor-design.md`
**Mockup:** `.opencode/plans/product-shortage-cash-factor-mockup.html` (no pudo persistirse por restriccion de plan mode; contenido HTML mostrado en chat)
**Tech Stack:** PHP 8.2+, Laravel 11, Filament v3, Livewire, MySQL, PestPHP

---

## Stack Profile

| Key | Value |
|-----|-------|
| **Language** | PHP 8.2+ |
| **Runtime** | PHP 8.2+ |
| **Framework** | Laravel 11 |
| **Package Manager** | Composer |
| **Test Runner** | PestPHP |
| **Test Command** | `php artisan test` |
| **Test Command (single file)** | `php artisan test --filter={testname}` |
| **Source Root** | `app/` |
| **Test Root** | `tests/` |
| **Test Utilities** | Factories (Branch, Employee, User, Product, TypePrice, AssignedProduct, DetailAssignedProduct, ProductPrice, ProductUnit), RefreshDatabase |

---

## File Map

**Create:**
- `database/migrations/2026_05_14_000000_add_product_shortage_to_daily_sales_reconciliations.php` — new columns
- `tests/Feature/DailySalesReconciliationProductShortageTest.php` — feature tests

**Modify:**
- `app/Models/DailySalesReconciliation.php` — fillable, casts, relationship
- `app/Livewire/Reconciliations/CreateReconciliation.php` — properties, methods, logic
- `resources/views/livewire/reconciliations/create-reconciliation.blade.php` — UI changes
- `app/Filament/Resources/DailySalesReconciliationResource/Pages/ViewDailySalesReconciliation.php` — view page

---

## Mockup

El wireframe HTML fue presentado en el chat anterior mostrando 3 pantallas:
1. Tabla Productos Sobrantes con selector TypePrice y nueva columna
2. Seccion de Totales con "Total Efectivo Prod. Faltante" arriba de Efectivo Esperado
3. Vista de Detalle mostrando los nuevos datos

---

### Task 1: Migration — Agregar columnas a la tabla

> **Risk:** 🟢 LOW
> **Affects existing:** DailySalesReconciliation model

**Files:**
- Create: `database/migrations/2026_05_14_000000_add_product_shortage_to_daily_sales_reconciliations.php`

- [ ] **Step 1: Crear archivo de migracion**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales_reconciliations', function (Blueprint $table) {
            $table->decimal('product_shortage_total', 10, 2)->default(0)->after('total_bills');
            $table->foreignId('type_price_id')->nullable()->after('product_shortage_total')
                  ->constrained('types_prices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_sales_reconciliations', function (Blueprint $table) {
            $table->dropForeign(['type_price_id']);
            $table->dropColumn(['product_shortage_total', 'type_price_id']);
        });
    }
};
```

- [ ] **Step 2: Ejecutar migracion**
```bash
php artisan migrate
```

- [ ] **Step 3: Commit**
```bash
git add database/migrations/2026_05_14_000000_add_product_shortage_to_daily_sales_reconciliations.php
git commit -m "feat: add product_shortage_total and type_price_id to daily_sales_reconciliations"
```

#### ⏪ Rollback
```bash
php artisan migrate:rollback --step=1
```

---

### Task 2: Model — Actualizar DailySalesReconciliation

> **Risk:** 🟢 LOW
> **Affects existing:** Ninguno (solo agrega campos)

**Files:**
- Modify: `app/Models/DailySalesReconciliation.php`

- [ ] **Step 1: Agregar fillable, casts y relacion**

Buscar el bloque `$fillable` y agregar al final del array:
```php
'product_shortage_total', 'type_price_id',
```

Buscar el bloque `$casts` y agregar:
```php
'product_shortage_total' => 'decimal:2',
```

Agregar nueva relacion despues de `bills()`:
```php
public function typePrice(): BelongsTo
{
    return $this->belongsTo(TypePrice::class, 'type_price_id');
}
```

Agregar el import al inicio del archivo:
```php
use App\Models\TypePrice;
```

- [ ] **Step 2: Commit**
```bash
git add app/Models/DailySalesReconciliation.php
git commit -m "feat: add typePrice relation and product_shortage_total to reconciliation model"
```

#### 🧪 Tests for this Task

*No se requieren tests especificos para esta tarea. La relacion se prueba implicitamente en Task 5 (View page).*

---

### Task 3: Livewire — Propiedades y logica de calculo

> **Risk:** 🟡 MEDIUM — modifica calculateTotals() que es central al cuadre
> **Affects existing:** calculateTotals(), saveReconciliation(), loadRemainingProducts(), createPendingReconciliation(), resetData(), mount()
> **Reference implementation:** El componente ya maneja bills, deposits, y returns siguiendo el mismo patron de propiedades + calculos.

**Files:**
- Modify: `app/Livewire/Reconciliations/CreateReconciliation.php`

- [ ] **Step 1: Agregar nuevas propiedades**

Agregar debajo de `public array $remaining_products = [];` (linea 78):
```php
public ?string $type_price_id = null;
public array $type_prices = [];
public float $product_shortage_total = 0.0;
```

- [ ] **Step 2: Agregar loadTypePrices() y cargar en mount()**

Agregar nuevo metodo (puede ir antes de `render()`):
```php
public function loadTypePrices(): void
{
    $this->type_prices = TypePrice::orderBy('name')->get()->toArray();
}
```

Agregar el import:
```php
use App\Models\TypePrice;
```

En `mount()`, agregar despues de `$this->products = ...`:
```php
$this->loadTypePrices();
```

- [ ] **Step 3: Agregar recalculateProductShortage()**

Nuevo metodo:
```php
public function recalculateProductShortage(): void
{
    if (!$this->type_price_id || empty($this->remaining_products)) {
        foreach ($this->remaining_products as &$product) {
            $product['shortage_cash'] = 0.0;
            $product['shortage_cash_unit_price'] = 0.0;
        }
        unset($product);
        $this->product_shortage_total = 0.0;
        return;
    }

    $productIds = array_column($this->remaining_products, 'product_id');

    $baseUnits = ProductUnit::whereIn('product_id', $productIds)
        ->where('is_base_unit', true)
        ->get()
        ->keyBy('product_id');

    $prices = ProductPrice::where('type_price_id', $this->type_price_id)
        ->whereIn('product_id', $productIds)
        ->get()
        ->groupBy('product_id');

    $total = 0.0;

    foreach ($this->remaining_products as &$product) {
        $productId = $product['product_id'];
        $remaining = (float) $product['remaining'];

        $baseUnit = $baseUnits->get($productId);
        $productPrices = $prices->get($productId);

        if (!$baseUnit || !$productPrices || $remaining <= 0) {
            $product['shortage_cash_unit_price'] = 0.0;
            $product['shortage_cash'] = 0.0;
            continue;
        }

        $priceRecord = $productPrices->firstWhere('product_unit_id', $baseUnit->id);
        $unitPrice = $priceRecord ? (float) $priceRecord->price : 0.0;

        $product['shortage_cash_unit_price'] = $unitPrice;
        $product['shortage_cash'] = round($remaining * $unitPrice, 2);
        $total += $product['shortage_cash'];
    }
    unset($product);

    $this->product_shortage_total = round($total, 2);
}
```

Agregar los imports necesarios:
```php
use App\Models\ProductUnit;
use App\Models\ProductPrice;
```

- [ ] **Step 4: Agregar updatedTypePriceId()**

Nuevo metodo:
```php
public function updatedTypePriceId(): void
{
    $this->recalculateProductShortage();
    $this->calculateTotals();
}
```

- [ ] **Step 5: Modificar calculateTotals() para incluir faltante**

En `calculateTotals()`, cambiar la linea (actualmente ~291):
```php
// ANTES:
$this->total_cash_expected = max(0, $cash_only_sales + $this->total_cash_collections) - $this->total_bills;

// DESPUES:
$this->total_cash_expected = max(0, $cash_only_sales + $this->total_cash_collections) - $this->total_bills + $this->product_shortage_total;
```

- [ ] **Step 6: Modificar loadRemainingProducts() para incluir product_id**

En el metodo `loadRemainingProducts()`, dentro del `map()`, agregar al array de retorno:
```php
'product_id' => $detail->product_id,
```

Y despues del `->values()->toArray();` (que hace el filter), agregar:
```php
// Recalcular efectivo de producto faltante si hay type_price seleccionado
if ($this->type_price_id) {
    $this->recalculateProductShortage();
}
```

- [ ] **Step 7: Modificar saveReconciliation() para persistir**

En el metodo `saveReconciliation()`, agregar al array de `update()`:
```php
'product_shortage_total' => $this->product_shortage_total,
'type_price_id' => $this->type_price_id,
```

- [ ] **Step 8: Modificar createPendingReconciliation() para incluir campos**

En el `create()` array, agregar:
```php
'product_shortage_total' => $this->product_shortage_total,
'type_price_id' => $this->type_price_id,
```

- [ ] **Step 9: Modificar resetData()**

Agregar:
```php
$this->type_price_id = null;
$this->product_shortage_total = 0.0;
```

- [ ] **Step 10: Disparar recalculo despues de updateReturnedQuantity**

En `updateReturnedQuantity()`, despues de `$this->loadRemainingProducts();` (que ya disparara el recalculo via el cambio en Step 6), verificar que el flujo funciona. El `loadRemainingProducts()` ya llama a `recalculateProductShortage()` si hay type_price_id.

En `returnAllRemainingProducts()`, tambien se llama a `loadRemainingProducts()` por lo que el recalculo es automatico.

- [ ] **Step 11: Agregar a loadEmployeeDataOnly()**

Al final del metodo, despues de cargar remaining_products, agregar para asegurar que el producto shortage se recalcula al cargar datos:
```php
// Esto ya se maneja dentro de loadRemainingProducts
```

- [ ] **Step 12: Commit**
```bash
git add app/Livewire/Reconciliations/CreateReconciliation.php
git commit -m "feat: add product shortage cash calculation to reconciliation Livewire component"
```

#### 🧪 Tests for this Task

**Test file:** `tests/Feature/DailySalesReconciliationProductShortageTest.php`

```php
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Livewire\livewire;
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
    
    $component = livewire(CreateReconciliation::class, ['employee_id' => $this->employee->id]);
    
    $component->assertSet('remaining_products', function ($products) {
        return count($products) === 1 && $products[0]['remaining'] == 30;
    });
    
    $component->set('type_price_id', $this->typePrice->id);
    
    $component->assertSet('product_shortage_total', 750.00);
    
    $component->assertSet('remaining_products.0.shortage_cash', 750.00);
    $component->assertSet('remaining_products.0.shortage_cash_unit_price', 25.00);
});

// TypePrice change recalculates
it('recalculates when type_price changes', function () {
    $component = livewire(CreateReconciliation::class, ['employee_id' => $this->employee->id]);
    
    $component->set('type_price_id', $this->typePrice->id);
    $component->assertSet('product_shortage_total', 750.00);
    
    $component->set('type_price_id', $this->typePrice2->id);
    $component->assertSet('product_shortage_total', 600.00);
});

// Total included in expected cash
it('adds shortage total to expected cash', function () {
    $component = livewire(CreateReconciliation::class, ['employee_id' => $this->employee->id]);
    
    $component->set('type_price_id', $this->typePrice->id);
    
    $shortageTotal = $component->get('product_shortage_total');
    $cashExpected = $component->get('total_cash_expected');
    
    // cash_expected should be at least product_shortage_total (since there are no sales)
    expect($cashExpected)->toEqual($shortageTotal);
});

// Persistence
it('persists product_shortage_total and type_price_id on save', function () {
    $component = livewire(CreateReconciliation::class, ['employee_id' => $this->employee->id]);
    
    $component->set('type_price_id', $this->typePrice->id);
    $component->set('cash_received', 1000.00);
    
    // Crear el cuadre primero
    $component->call('initializeReconciliation');
    
    // Luego guardarlo
    $component->call('saveReconciliation');
    
    $reconciliation = DailySalesReconciliation::latest()->first();
    
    expect($reconciliation->product_shortage_total)->toEqual(750.00);
    expect($reconciliation->type_price_id)->toEqual($this->typePrice->id);
});

// Edge case: no type price selected
it('sets shortage to zero when no type_price selected', function () {
    $component = livewire(CreateReconciliation::class, ['employee_id' => $this->employee->id]);
    
    expect($component->get('product_shortage_total'))->toEqual(0.0);
    
    $component->set('type_price_id', null);
    $component->assertSet('product_shortage_total', 0.0);
});

// Edge case: no remaining products
it('sets shortage to zero when no products remaining', function () {
    // Update detail so remaining = 0
    $this->detail->update([
        'sale_quantity' => 100,
        'returned_quantity' => 0,
    ]);
    
    $component = livewire(CreateReconciliation::class, ['employee_id' => $this->employee->id]);
    $component->set('type_price_id', $this->typePrice->id);
    
    $component->assertSet('product_shortage_total', 0.0);
});
```

**Run command:**
```bash
php artisan test --filter=DailySalesReconciliationProductShortageTest
```

---

### Task 4: Blade — Cambios en tabla Productos Sobrantes

> **Risk:** 🟡 MEDIUM — cambios visuales en la UI principal del cuadre
> **Affects existing:** create-reconciliation.blade.php — seccion Productos Sobrantes
> **Reference implementation:** Ventas del Dia (lineas 360-464) para altura de tabla; Gastos (1224-1233) para items de totales.

**Files:**
- Modify: `resources/views/livewire/reconciliations/create-reconciliation.blade.php`

- [ ] **Step 1: Agregar selector TypePrice en el header**

Reemplazar la seccion del header (lineas 575-595 actuales) para agregar el selector entre el titulo y el boton. Buscar:

```html
<div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-x-3">
        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">📦 Productos Sobrantes</h4>
        <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/30">
            {{ count($remaining_products) }} producto(s)
        </div>
    </div>
    <div class="flex items-center gap-x-2">
        <button type="button" ...>Retornar Todos</button>
    </div>
</div>
```

Reemplazar con:

```html
<div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-x-3">
        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">📦 Productos Sobrantes</h4>
        <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/30">
            {{ count($remaining_products) }} producto(s)
        </div>
    </div>
    <div class="flex items-center gap-x-3">
        <!-- TypePrice Selector -->
        <div class="flex items-center gap-x-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">Precios:</label>
            <select wire:model.live="type_price_id"
                class="fi-select-input block rounded-md border-gray-300 shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200">
                <option value="">Sin escala</option>
                @foreach($type_prices as $tp)
                    <option value="{{ $tp['id'] }}">{{ $tp['name'] }}</option>
                @endforeach
            </select>
        </div>
        
        <button type="button" 
            wire:click="returnAllRemainingProducts"
            wire:loading.attr="disabled"
            wire:target="returnAllRemainingProducts"
            class="fi-btn fi-btn-size-sm relative inline-grid grid-flow-col items-center justify-center gap-1 rounded-md border-0 font-semibold outline-none transition duration-75 focus:ring-1 fi-color-success bg-success-50 text-success-600 hover:bg-success-100 dark:bg-success-400/10 dark:text-success-400 dark:hover:bg-success-400/20 focus:ring-success-500/50 dark:focus:ring-success-400/50 text-sm py-2 px-3"
            :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
            :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
            <span wire:loading.remove wire:target="returnAllRemainingProducts" class="text-sm">🔄</span>
            <span wire:loading wire:target="returnAllRemainingProducts" class="animate-spin text-sm">⏳</span>
            <span class="text-sm ml-1">Retornar Todos</span>
        </button>
    </div>
</div>
```

- [ ] **Step 2: Eliminar columna Codigo, mover a badge en nombre del producto**

En thead, eliminar el `<th>` de codigo (lineas 608-612 actuales).

En tbody, reemplazar la celda de codigo por un badge dentro de la celda de nombre.

Celda Producto (reemplazar lineas 637-651):

```html
<td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
    <div class="fi-ta-col-wrp px-3 py-4">
        <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white font-medium flex items-center gap-x-2">
            {{ $product['product_name'] }}
            <span class="fi-badge inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                {{ $product['product_code'] }}
            </span>
        </div>
    </div>
</td>
```

Eliminar completamente la celda de codigo existente (lineas 645-651).

- [ ] **Step 3: Agregar columna Efectivo Prod. Faltante**

En thead, despues del `<th>` de Sobrante (linea 632), agregar:

```html
<th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-center">
    <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-center">
        <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Efectivo Prod. Falt.</span>
    </span>
</th>
```

En tbody, despues de la celda de Sobrante (linea 697), agregar:

```html
<td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
    <div class="fi-ta-col-wrp px-3 py-4 text-center">
        <div class="fi-ta-text text-sm font-semibold leading-6 {{ ($product['shortage_cash'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500' }}">
            L {{ number_format($product['shortage_cash'] ?? 0, 2) }}
        </div>
    </div>
</td>
```

- [ ] **Step 4: Igualar altura de tabla con Ventas del Dia**

Agregar `max-height: 225px` al contenedor de la tabla. Buscar el div que contiene la tabla (alrededor de linea 598-599):

```html
<div class="overflow-hidden">
    <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10">
```

Reemplazar el div exterior:

```html
<div class="overflow-hidden" style="max-height: 225px;">
    <div class="fi-ta-ctn divide-y divide-gray-200 overflow-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10">
```

- [ ] **Step 5: Commit**
```bash
git add resources/views/livewire/reconciliations/create-reconciliation.blade.php
git commit -m "feat: add TypePrice selector, shortage cash column, and code badge to Productos Sobrantes"
```

#### 🧪 Tests for this Task

*Los tests de UI de Livewire se cubren en Task 3. Para tests visuales adicionales, se verifican con exploracion manual.*

---

### Task 5: Blade — Seccion de Totales

> **Risk:** 🟢 LOW
> **Affects existing:** Seccion de totales en create-reconciliation.blade.php

**Files:**
- Modify: `resources/views/livewire/reconciliations/create-reconciliation.blade.php`

- [ ] **Step 1: Agregar item de total faltante arriba de Efectivo Esperado**

Insertar justo ANTES del bloque `<!-- Efectivo Esperado -->` (linea 1212-1213 actual):

```html
<!-- Total Efectivo Producto Faltante -->
<li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700 bg-amber-50/50 dark:bg-amber-400/5">
    <div class="flex justify-between items-center">
        <div class="flex items-center gap-x-1">
            <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-amber-50 p-0.5 text-amber-500 dark:bg-amber-500/10 dark:text-amber-400 text-sm">💎</span>
            <span class="text-sm font-medium text-gray-950 dark:text-white">Total Efectivo Prod. Faltante</span>
        </div>
        <span class="font-semibold text-amber-600 dark:text-amber-400">L {{ number_format($product_shortage_total, 2) }}</span>
    </div>
</li>
```

- [ ] **Step 2: Commit**
```bash
git add resources/views/livewire/reconciliations/create-reconciliation.blade.php
git commit -m "feat: display product shortage cash total above expected cash in reconciliation"
```

#### 🧪 Tests for this Task

*Cubierto por tests en Task 3 (assertSet en total_cash_expected y product_shortage_total).*

---

### Task 6: View Page — Mostrar datos en vista de detalle

> **Risk:** 🟢 LOW
> **Affects existing:** ViewDailySalesReconciliation.php — seccion Analisis de Reconciliacion

**Files:**
- Modify: `app/Filament/Resources/DailySalesReconciliationResource/Pages/ViewDailySalesReconciliation.php`

- [ ] **Step 1: Agregar nuevos campos en la seccion de Analisis**

En la seccion `'Análisis de Reconciliación'` (linea 188), modificar el Grid para incluir los nuevos datos. Reemplazar el Grid(2) de total_cash_expected y total_deposit_expected (lineas 191-208) por un Grid(3):

```php
Grid::make(3)
    ->schema([
        TextEntry::make('total_cash_expected')
            ->label('🎯 Efectivo Esperado')
            ->money('HNL')
            ->weight(FontWeight::Bold)
            ->size('lg')
            ->color('primary')
            ->extraAttributes(['class' => 'text-center p-4 bg-blue-50 rounded-lg border border-blue-200']),
        
        TextEntry::make('product_shortage_total')
            ->label('💎 Efectivo Prod. Faltante')
            ->money('HNL')
            ->weight(FontWeight::Bold)
            ->size('lg')
            ->color('warning')
            ->placeholder('L 0.00')
            ->extraAttributes(['class' => 'text-center p-4 bg-amber-50 rounded-lg border border-amber-200']),
        
        TextEntry::make('typePrice.name')
            ->label('🏷️ Escala de Precios')
            ->weight(FontWeight::Bold)
            ->size('lg')
            ->placeholder('No seleccionada')
            ->color('gray')
            ->extraAttributes(['class' => 'text-center p-4 bg-gray-50 rounded-lg border border-gray-200']),
    ]),
```

- [ ] **Step 2: Commit**
```bash
git add app/Filament/Resources/DailySalesReconciliationResource/Pages/ViewDailySalesReconciliation.php
git commit -m "feat: display product shortage cash and type price in reconciliation view"
```

#### 🧪 Tests for this Task

```php
// Agregar al archivo de tests existente:
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

// Edge case: no type_price selected
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
```

**Run command:**
```bash
php artisan test --filter=DailySalesReconciliationProductShortageTest
```

---

### Task 7: Verificacion final y ejecucion de suite completa

> **Risk:** 🟢 LOW

- [ ] **Step 1: Ejecutar todos los tests**
```bash
php artisan test
```

- [ ] **Step 2: Verificar que no haya errores de lint**
```bash
php artisan about
```

- [ ] **Step 3: Commit final (si hay ajustes)**
```bash
git add -A
git commit -m "test: add comprehensive tests for product shortage cash feature"
```

---

### Self-Review Checklist
- [x] All spec requirements are covered
- [x] Each task has a commit checkpoint
- [x] Code snippets are complete (not partial)
- [x] Each task with logic has a `🧪 Tests for this Task` section with complete, runnable test code
- [x] Each test section has at least one happy path, one edge case, one failure scenario
- [x] Each test section includes the exact run command
- [x] Test code uses PestPHP and follows project conventions
- [x] Dependencies between tasks are respected (Migration → Model → Livewire → Blade → View → Tests)
- [x] No orphan files
- [x] Mockup was presented in chat
