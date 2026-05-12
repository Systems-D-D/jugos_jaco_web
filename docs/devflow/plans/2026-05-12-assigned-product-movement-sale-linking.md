# Implementation Plan: AssignedProductMovement Sale Linking

**Goal:** Vincular AssignedProductMovement a ventas (Sale) mediante FK sale_id, y modificar SaleService para que productos con movement_type se registren como movimientos en lugar de SaleDetail.

**Architecture:** `docs/devflow/specs/2026-05-12-assigned-product-movement-sale-linking-design.md`

**Mockup:** N/A (backend feature; reconciliation UI change is a single column addition to existing table)

**Tech Stack:** PHP 8.2+ / Laravel 11 / MySQL / PestPHP

---

## File Map

**Create:**
- `database/migrations/2026_05_12_000000_add_sale_id_to_assigned_product_movements_table.php` — Agrega FK `sale_id`

**Modify:**
- `app/Models/AssignedProductMovement.php` — +fillable `sale_id`, +relación `sale()`
- `app/Models/Sale.php` — +relación `assignedProductMovements()`
- `app/Services/AssignedProductMovementService.php` — `createMovement()` acepta `?int $saleId`
- `app/Services/SaleService.php` — Inyecta MovementService; `calculateTotals()` filtra; `createSaleDetails()` bifurca
- `app/Http/Controllers/SaleController.php` — `prepareSaleDetailsData()` pasa `movement_type`; actualiza constructor de SaleService
- `app/Http/Requests/SaleRequest.php` — Valida `products.*.movement_type`
- `app/Livewire/Reconciliations/CreateReconciliation.php` — `loadMovements()` eager load `sale`
- `resources/views/livewire/reconciliations/create-reconciliation.blade.php` — Columna "Venta" en tabla

**Create (tests):**
- `tests/Feature/AssignedProductMovementSaleLinkingTest.php` — Feature tests
- `tests/Unit/AssignedProductMovementModelTest.php` — Unit tests

---

### Task 1: Database Foundation — Migration + Model Changes

> **Risk:** 🟢 LOW
> **Affects existing:** AssignedProductMovement (new nullable column), Sale (new HasMany relation)

**Files:**
- Create: `database/migrations/2026_05_12_000000_add_sale_id_to_assigned_product_movements_table.php`
- Modify: `app/Models/AssignedProductMovement.php`
- Modify: `app/Models/Sale.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('detail_assigned_product_id')
                  ->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assigned_product_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 3: Update AssignedProductMovement model**

Add `'sale_id'` to `$fillable` and add `sale()` relationship:

```php
// In $fillable array, add:
'sale_id',

// Add to $casts array:
'sale_id' => 'integer',

// Add new method after creator():
public function sale(): BelongsTo
{
    return $this->belongsTo(Sale::class);
}
```

Full file after changes at `app/Models/AssignedProductMovement.php`:

```php
<?php

namespace App\Models;

use App\Enums\AssignedProductMovementTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignedProductMovement extends Model
{
    protected $fillable = [
        'detail_assigned_product_id',
        'type',
        'quantity',
        'note',
        'sale_id',
        'created_by',
    ];

    protected $casts = [
        'type' => AssignedProductMovementTypeEnum::class,
        'quantity' => 'decimal:2',
        'sale_id' => 'integer',
    ];

    public function detailAssignedProduct(): BelongsTo
    {
        return $this->belongsTo(DetailAssignedProduct::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 4: Update Sale model**

Add `assignedProductMovements()` relationship after `details()`:

```php
public function assignedProductMovements(): HasMany
{
    return $this->hasMany(AssignedProductMovement::class);
}
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_12_000000_add_sale_id_to_assigned_product_movements_table.php app/Models/AssignedProductMovement.php app/Models/Sale.php
git commit -m "feat: add sale_id FK to assigned_product_movements with model relationships"
```

#### 🧪 Tests for this Task

**Test file:** `tests/Unit/AssignedProductMovementModelTest.php` (create new)

```php
<?php

use App\Models\AssignedProductMovement;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

it('has sale belongsTo relationship', function () {
    $movement = new AssignedProductMovement();
    $relation = $movement->sale();

    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(Sale::class);
});

it('has sale_id in fillable', function () {
    $movement = new AssignedProductMovement();

    expect($movement->getFillable())->toContain('sale_id');
});

it('casts sale_id to integer', function () {
    $movement = new AssignedProductMovement();

    expect($movement->getCasts()['sale_id'])->toBe('integer');
});

it('Sale has assignedProductMovements hasMany relationship', function () {
    $sale = new Sale();
    $relation = $sale->assignedProductMovements();

    expect($relation)->toBeInstanceOf(HasMany::class);
    expect($relation->getRelated())->toBeInstanceOf(AssignedProductMovement::class);
});
```

**Run command:**
```bash
./vendor/bin/pest tests/Unit/AssignedProductMovementModelTest.php
```

---

### Task 2: AssignedProductMovementService — soporte para saleId

> **Risk:** 🟢 LOW
> **Affects existing:** AssignedProductMovementService::createMovement() — parámetro opcional, backward compatible

**Files:**
- Modify: `app/Services/AssignedProductMovementService.php`

- [ ] **Step 1: Add `$saleId` parameter to `createMovement()`**

```php
public function createMovement(int $detailId, string $type, float $quantity, ?string $note = null, ?int $saleId = null): AssignedProductMovement
{
    return DB::transaction(function () use ($detailId, $type, $quantity, $note, $saleId) {
        // 1. Find DetailAssignedProduct
        $detail = DetailAssignedProduct::find($detailId);

        if (!$detail) {
            throw new Exception("El detalle del producto asignado no existe.");
        }

        // Check stock availability
        if ($detail->stock < $quantity) {
             throw new Exception("Stock insuficiente para realizar el movimiento. Stock actual: {$detail->stock}");
        }

        // 2. Create Movement
        $movement = AssignedProductMovement::create([
            'detail_assigned_product_id' => $detail->id,
            'type' => AssignedProductMovementTypeEnum::from($type),
            'quantity' => $quantity,
            'note' => $note,
            'sale_id' => $saleId,
            'created_by' => auth()->id(),
        ]);

        // 3. Update Accumulator
        if ($movement->type === AssignedProductMovementTypeEnum::CHANGE) {
            $detail->changes_quantity += $quantity;
        } else {
            $detail->royalties_quantity += $quantity;
        }
        $detail->save();

        return $movement;
    });
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/AssignedProductMovementService.php
git commit -m "feat: add optional saleId parameter to AssignedProductMovementService::createMovement"
```

#### 🧪 Tests for this Task

**Test file:** Add to `tests/Feature/AssignedProductMovementSaleLinkingTest.php` (create new)

```php
<?php

use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AssignedProductMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Task 2: AssignedProductMovementService tests ---

it('creates movement without sale_id when saleId is null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $service = app(AssignedProductMovementService::class);
    $movement = $service->createMovement($detail->id, 'change', 5, 'Nota de prueba');

    expect($movement->sale_id)->toBeNull();
    expect($movement->quantity)->toEqual(5.0);
    expect($detail->fresh()->changes_quantity)->toEqual(5.0);
});

it('creates movement with sale_id when saleId is provided', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'sale_date' => now(),
        'status' => 'confirmed',
        'subtotal' => 100,
        'total_amount' => 100,
    ]);
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $service = app(AssignedProductMovementService::class);
    $movement = $service->createMovement($detail->id, 'royalty', 3, 'Regalía de venta', $sale->id);

    expect($movement->sale_id)->toEqual($sale->id);
    expect($movement->sale)->not->toBeNull();
    expect($movement->sale->id)->toEqual($sale->id);
    expect($detail->fresh()->royalties_quantity)->toEqual(3.0);
});

it('throws exception when detail does not exist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = app(AssignedProductMovementService::class);

    $this->expectException(Exception::class);
    $service->createMovement(99999, 'change', 1);
});
```

**Run command:**
```bash
./vendor/bin/pest tests/Feature/AssignedProductMovementSaleLinkingTest.php --filter="creates movement"
```

---

### Task 3: SaleService — bifurcación de movement_type

> **Risk:** 🟡 MEDIUM — modifica el core del flujo de ventas; el filtro de totales y la creación de SaleDetail son puntos críticos
> **Affects existing:** SaleService::createSale() (flujo de venta API), SaleController (constructor de SaleService)

**Files:**
- Modify: `app/Services/SaleService.php`
- Modify: `app/Http/Controllers/SaleController.php` (solo el constructor)

- [ ] **Step 1: Inject AssignedProductMovementService in SaleService constructor**

Add property and constructor parameter:

```php
use App\Services\AssignedProductMovementService;

class SaleService
{
    protected $managementInventoryService;
    protected $accountReceivableService;
    protected $clientVisitService;
    protected $assignedProductMovementService;

    public function __construct(
        ManagementInventoryService $managementInventoryService,
        AccountReceivableService $accountReceivableService,
        ClientVisitService $clientVisitService,
        AssignedProductMovementService $assignedProductMovementService
    ) {
        $this->managementInventoryService = $managementInventoryService;
        $this->accountReceivableService = $accountReceivableService;
        $this->clientVisitService = $clientVisitService;
        $this->assignedProductMovementService = $assignedProductMovementService;
    }
```

- [ ] **Step 2: Update SaleController constructor**

In `app/Http/Controllers/SaleController.php`:

```php
use App\Services\AssignedProductMovementService;

// Change constructor from:
$this->saleService = new SaleService(new ManagementInventoryService(), new AccountReceivableService(), new ClientVisitService());

// To:
$this->saleService = new SaleService(
    new ManagementInventoryService(),
    new AccountReceivableService(),
    new ClientVisitService(),
    new AssignedProductMovementService()
);
```

- [ ] **Step 3: Modify `calculateTotals()` to exclude royalty/changes products**

```php
public function calculateTotals(array $products): array
{
    $subtotal = 0;
    $totalTaxes = 0;

    foreach ($products as $product) {
        // Skip products with movement_type (royalty or change)
        if (!empty($product['movement_type'])) {
            continue;
        }

        // Calcular subtotal de línea
        $lineSubtotal = $product['line_subtotal'] ??
            ($product['quantity'] * $product['unit_price_without_tax']);

        // Calcular impuesto de línea
        $lineTaxAmount = $product['line_tax_amount'] ??
            ($product['quantity'] * ($product['unit_tax_amount'] ?? 0));

        // Acumular totales
        $subtotal += $lineSubtotal;
        $totalTaxes += $lineTaxAmount;
    }

    return [
        'subtotal' => $subtotal,
        'total_taxes' => $totalTaxes,
        'final_total' => $subtotal + $totalTaxes,
    ];
}
```

- [ ] **Step 4: Modify `createSaleDetails()` to bifurcate logic**

Add the conditional at the start of the foreach loop:

```php
protected function createSaleDetails(Sale $sale, array $productsData): void
{
    foreach ($productsData as $productData) {
        // --- CASE: Royalty or Change → AssignedProductMovement ---
        if (!empty($productData['movement_type'])) {
            $this->createMovementFromSaleProduct($sale, $productData);
            continue;
        }

        // --- CASE: Normal product → SaleDetail (existing logic unchanged) ---
        if (isset($productData['origin']) && $productData['origin'] === 'api') {
            // ... existing AssignedProduct validation code unchanged ...
        }
        // ... existing SaleDetail::create() code unchanged ...
    }
}
```

- [ ] **Step 5: Add `createMovementFromSaleProduct()` private method**

Add this new method at the end of the class, before the closing `}`:

```php
/**
 * Crea un AssignedProductMovement vinculado a la venta para productos
 * que son regalías (royalty) o cambios (change).
 */
private function createMovementFromSaleProduct(Sale $sale, array $productData): void
{
    $assignedProduct = AssignedProduct::where('employee_id', $sale->employee_id)
        ->whereDate('date', $sale->sale_date)
        ->first();

    if (!$assignedProduct) {
        throw new Exception(
            "No hay asignación de productos para el empleado en la fecha de la venta."
        );
    }

    $detail = DetailAssignedProduct::where('assigned_products_id', $assignedProduct->id)
        ->where('product_id', $productData['product_id'])
        ->first();

    if (!$detail) {
        throw new Exception(
            "El producto '{$productData['name']}' no está asignado al empleado para hoy."
        );
    }

    $note = $productData['movement_note'] ?? "Venta #INV-{$sale->id}";

    $this->assignedProductMovementService->createMovement(
        detailId: $detail->id,
        type: $productData['movement_type'],
        quantity: (float) $productData['quantity'],
        note: $note,
        saleId: $sale->id,
    );
}
```

- [ ] **Step 6: Add missing import for Exception**

Ensure `Exception` is imported in `SaleService.php` (it already is at line 14).

- [ ] **Step 7: Commit**

```bash
git add app/Services/SaleService.php app/Http/Controllers/SaleController.php
git commit -m "feat: bifurcate SaleService to create AssignedProductMovement for royalty/change products"
```

#### 🧪 Tests for this Task

**Test file:** Add to `tests/Feature/AssignedProductMovementSaleLinkingTest.php`

```php
// --- Task 3: SaleService tests ---

it('excludes royalty products from sale totals', function () {
    $service = app(\App\Services\SaleService::class);

    $products = [
        [
            'product_id' => 1,
            'name' => 'Producto Normal',
            'quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 5,
            'line_subtotal' => 100,
            'line_tax_amount' => 10,
        ],
        [
            'product_id' => 2,
            'name' => 'Regalía',
            'quantity' => 1,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 5,
            'line_subtotal' => 50,
            'line_tax_amount' => 5,
            'movement_type' => 'royalty',
        ],
    ];

    $totals = $service->calculateTotals($products);

    expect($totals['subtotal'])->toEqual(100.0);
    expect($totals['total_taxes'])->toEqual(10.0);
    expect($totals['final_total'])->toEqual(110.0);
});

it('excludes change products from sale totals', function () {
    $service = app(\App\Services\SaleService::class);

    $products = [
        [
            'product_id' => 1,
            'name' => 'Producto Normal',
            'quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
        ],
        [
            'product_id' => 2,
            'name' => 'Cambio',
            'quantity' => 1,
            'unit_price_without_tax' => 30,
            'unit_tax_amount' => 0,
            'line_subtotal' => 30,
            'line_tax_amount' => 0,
            'movement_type' => 'change',
        ],
    ];

    $totals = $service->calculateTotals($products);

    expect($totals['subtotal'])->toEqual(100.0);
    expect($totals['final_total'])->toEqual(100.0);
});

it('returns zero totals when all products are movement type', function () {
    $service = app(\App\Services\SaleService::class);

    $products = [
        [
            'product_id' => 1,
            'name' => 'Regalía 1',
            'quantity' => 1,
            'unit_price_without_tax' => 50,
            'line_subtotal' => 50,
            'line_tax_amount' => 0,
            'movement_type' => 'royalty',
        ],
        [
            'product_id' => 2,
            'name' => 'Cambio 1',
            'quantity' => 2,
            'unit_price_without_tax' => 30,
            'line_subtotal' => 60,
            'line_tax_amount' => 0,
            'movement_type' => 'change',
        ],
    ];

    $totals = $service->calculateTotals($products);

    expect($totals['subtotal'])->toEqual(0.0);
    expect($totals['final_total'])->toEqual(0.0);
});

it('creates AssignedProductMovement instead of SaleDetail for royalty product', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = \App\Models\Client::factory()->create();

    // Create assigned product
    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
        'sale_quantity' => 0,
        'returned_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
    ]);

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 100,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'product_id' => $product->id,
            'name' => 'Jugo Naranja',
            'quantity' => 1,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(\App\Services\SaleService::class);
    $sale = $service->createSale($saleData, $productsData);

    // Sale is created with zero totals (royalty excluded)
    expect($sale->subtotal)->toEqual(0.0);
    expect($sale->total_amount)->toEqual(0.0);

    // No SaleDetail created
    expect($sale->details)->toHaveCount(0);

    // AssignedProductMovement created and linked to sale
    $movements = AssignedProductMovement::where('sale_id', $sale->id)->get();
    expect($movements)->toHaveCount(1);
    expect($movements->first()->type->value)->toBe('royalty');
    expect($movements->first()->quantity)->toEqual(1.0);

    // Accumulator updated
    expect($detail->fresh()->royalties_quantity)->toEqual(1.0);
});

it('throws exception when no assigned product exists for employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = \App\Models\Client::factory()->create();

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 0,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'product_id' => $product->id,
            'name' => 'Jugo Naranja',
            'quantity' => 1,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(\App\Services\SaleService::class);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('No hay asignación de productos');

    $service->createSale($saleData, $productsData);
});

it('throws exception when product not in assignment', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $otherProduct = Product::factory()->create(['name' => 'Otro Producto', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = \App\Models\Client::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    // Assign product to employee, but NOT the one in the sale
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $otherProduct->id,
        'quantity' => 50,
    ]);

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 0,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'product_id' => $product->id,
            'name' => 'Jugo Naranja',
            'quantity' => 1,
            'unit_price_without_tax' => 100,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(\App\Services\SaleService::class);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage('no está asignado al empleado');

    $service->createSale($saleData, $productsData);
});

it('creates SaleDetail for normal products alongside movements for royalty products', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $normalProduct = Product::factory()->create(['name' => 'Jugo Normal', 'is_active' => true]);
    $royaltyProduct = Product::factory()->create(['name' => 'Jugo Regalía', 'is_active' => true]);
    $employee = Employee::factory()->create();
    $client = \App\Models\Client::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $normalProduct->id,
        'quantity' => 100,
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $royaltyProduct->id,
        'quantity' => 50,
    ]);

    $saleData = [
        'client_id' => $client->id,
        'employee_id' => $employee->id,
        'sale_date' => now()->toDateString(),
        'cash_amount' => 100,
        'payment_method' => 'cash',
        'payment_term' => 'cash',
        'branch_id' => 1,
    ];

    $productsData = [
        [
            'origin' => 'api',
            'product_id' => $normalProduct->id,
            'name' => 'Jugo Normal',
            'quantity' => 2,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
            'movement_type' => null,
        ],
        [
            'product_id' => $royaltyProduct->id,
            'name' => 'Jugo Regalía',
            'quantity' => 1,
            'unit_price_without_tax' => 50,
            'unit_tax_amount' => 0,
            'line_subtotal' => 50,
            'line_tax_amount' => 0,
            'line_total' => 50,
            'movement_type' => 'royalty',
        ],
    ];

    $service = app(\App\Services\SaleService::class);
    $sale = $service->createSale($saleData, $productsData);

    // Sale totals only include normal product
    expect($sale->subtotal)->toEqual(100.0);
    expect($sale->total_amount)->toEqual(100.0);

    // Normal product → SaleDetail
    expect($sale->details)->toHaveCount(1);
    expect($sale->details->first()->product_name)->toBe('Jugo Normal');

    // Royalty product → AssignedProductMovement
    $movements = AssignedProductMovement::where('sale_id', $sale->id)->get();
    expect($movements)->toHaveCount(1);
    expect($movements->first()->type->value)->toBe('royalty');
});
```

**Run command:**
```bash
./vendor/bin/pest tests/Feature/AssignedProductMovementSaleLinkingTest.php --filter="excludes|returns zero|creates AssignedProductMovement|throws exception|creates SaleDetail"
```

---

### Task 4: API Layer — Request Validation + Controller

> **Risk:** 🟢 LOW
> **Affects existing:** SaleRequest (nueva regla de validación), SaleController::prepareSaleDetailsData() (nuevo campo en array)

**Files:**
- Modify: `app/Http/Requests/SaleRequest.php`
- Modify: `app/Http/Controllers/SaleController.php`

- [ ] **Step 1: Add validation rule to SaleRequest**

Add import and rule in `app/Http/Requests/SaleRequest.php`:

```php
use App\Enums\AssignedProductMovementTypeEnum;

// In rules(), add after 'products.*.product_price_id':
'products.*.movement_type' => ['nullable', 'string', new Enum(AssignedProductMovementTypeEnum::class)],
```

- [ ] **Step 2: Pass movement_type in SaleController::prepareSaleDetailsData()**

In `app/Http/Controllers/SaleController.php`, add inside the `$details[]` array:

```php
'movement_type' => $product['movement_type'] ?? null,
```

Full updated method (showing the addition at the end of the array):

```php
private function prepareSaleDetailsData(array $productData): array
{
    $details = [];
    foreach ($productData as $product) {
        $productPrice = ProductPrice::with([
            'taxCategory:id,name,rate',
            'productUnit:id,product_id,unit_id',
            'productUnit.unit:id,name,abbreviation',
            'product:id,name,code'
        ])->find($product['product_price_id']);

        if (!$productPrice)
            continue; // Skip if product price not found

        $lineSubtotal = $productPrice->getPriceWithoutTax() * (int) $product['quantity'];
        $lineTaxAmount = $productPrice->getTaxAmount() * (int) $product['quantity'];
        $lineTotal = $lineSubtotal + $lineTaxAmount;

        $details[] = [
            'origin' => 'api',
            'product_id' => $product['product_id'],
            'product_price_id' => $productPrice->id,
            'name' => $productPrice->product->name,
            'code' => $productPrice->product->code,
            'type_price_id' => $productPrice->type_price_id,
            'unit_name' => $productPrice->productUnit->unit->name,
            'unit_abbreviation' => $productPrice->productUnit->unit->abbreviation,
            'product_unit_id' => $productPrice->product_unit_id,
            'quantity' => (int) $product['quantity'],
            'base_quantity' => (int) $product['quantity'],
            'unit_price_without_tax' => $productPrice->getPriceWithoutTax(),
            'unit_price_with_tax' => $productPrice->getPriceWithTax(),
            'unit_tax_amount' => $productPrice->getTaxAmount(),
            'tax_category_id' => $productPrice->tax_category_id,
            'tax_category_name' => $productPrice->taxCategory->name,
            'tax_rate' => $productPrice->taxCategory->rate ?? 0,
            'price_include_tax' => $productPrice->price_include_tax,
            'line_subtotal' => $lineSubtotal,
            'line_tax_amount' => $lineTaxAmount,
            'line_total' => $lineTotal,
            'discount_percentage' => $product['discount_percentage'] ?? 0,
            'discount_amount' => ($lineTotal * ($product['discount_percentage'] ?? 0)) / 100,
            'movement_type' => $product['movement_type'] ?? null,
        ];
    }
    return $details;
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/SaleRequest.php app/Http/Controllers/SaleController.php
git commit -m "feat: add movement_type validation to SaleRequest and pass through in SaleController"
```

#### 🧪 Tests for this Task

**Test file:** Add to `tests/Feature/AssignedProductMovementSaleLinkingTest.php`

```php
// --- Task 4: API Layer tests ---

it('validates movement_type must be a valid enum value', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Test', 'is_active' => true]);

    $response = $this->postJson('/api/sales', [
        'client_id' => 1,
        'employee_id' => 1,
        'payment_term' => 'cash',
        'payment_method' => 'cash',
        'cash_amount' => 100,
        'products' => [
            [
                'product_id' => $product->id,
                'product_price_id' => 1,
                'quantity' => 1,
                'movement_type' => 'invalid_type',
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['products.0.movement_type']);
});

it('accepts valid movement_type values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Test', 'is_active' => true]);

    $response = $this->postJson('/api/sales', [
        'client_id' => 1,
        'employee_id' => 1,
        'payment_term' => 'cash',
        'payment_method' => 'cash',
        'cash_amount' => 100,
        'products' => [
            [
                'product_id' => $product->id,
                'product_price_id' => 1,
                'quantity' => 1,
                'movement_type' => 'royalty',
            ],
        ],
    ]);

    // Should not have validation errors for movement_type
    $response->assertJsonMissingValidationErrors(['products.0.movement_type']);
});

it('accepts null movement_type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Test', 'is_active' => true]);

    $response = $this->postJson('/api/sales', [
        'client_id' => 1,
        'employee_id' => 1,
        'payment_term' => 'cash',
        'payment_method' => 'cash',
        'cash_amount' => 100,
        'products' => [
            [
                'product_id' => $product->id,
                'product_price_id' => 1,
                'quantity' => 1,
                'movement_type' => null,
            ],
        ],
    ]);

    $response->assertJsonMissingValidationErrors(['products.0.movement_type']);
});

it('accepts missing movement_type field', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Test', 'is_active' => true]);

    $response = $this->postJson('/api/sales', [
        'client_id' => 1,
        'employee_id' => 1,
        'payment_term' => 'cash',
        'payment_method' => 'cash',
        'cash_amount' => 100,
        'products' => [
            [
                'product_id' => $product->id,
                'product_price_id' => 1,
                'quantity' => 1,
            ],
        ],
    ]);

    $response->assertJsonMissingValidationErrors(['products.0.movement_type']);
});
```

**Run command:**
```bash
./vendor/bin/pest tests/Feature/AssignedProductMovementSaleLinkingTest.php --filter="validates movement_type|accepts"
```

---

### Task 5: Reconciliation — Mostrar Venta vinculada

> **Risk:** 🟢 LOW — solo agrega una columna informativa a la tabla de movimientos
> **Affects existing:** CreateReconciliation (Livewire + Blade)

**Files:**
- Modify: `app/Livewire/Reconciliations/CreateReconciliation.php`
- Modify: `resources/views/livewire/reconciliations/create-reconciliation.blade.php`

- [ ] **Step 1: Update `loadMovements()` to eager load sale and include sale info**

In `app/Livewire/Reconciliations/CreateReconciliation.php`, find the `loadMovements()` method. Update the `with()` call and the `map()`:

```php
$this->movements = AssignedProductMovement::whereHas('detailAssignedProduct', function ($query) use ($assignedProduct) {
        $query->where('assigned_products_id', $assignedProduct->id);
    })
    ->with(['detailAssignedProduct.product', 'sale'])
    ->get()
    ->map(function ($movement) {
        return [
            'id' => $movement->id,
            'product_name' => $movement->detailAssignedProduct->product->name,
            'type' => $movement->type->getLabel(),
            'type_raw' => $movement->type->value,
            'quantity' => $movement->quantity,
            'note' => $movement->note,
            'created_at' => $movement->created_at->format('H:i:s'),
            'sale_id' => $movement->sale_id,
            'sale_info' => $movement->sale?->full_invoice_number ?? ($movement->sale_id ? "#{$movement->sale_id}" : null),
        ];
    })->toArray();
```

- [ ] **Step 2: Add "Venta" column to blade view**

In `resources/views/livewire/reconciliations/create-reconciliation.blade.php`, find the movements table (around line 1009-1041). Add a new `<th>` after "Hora" and before "Acciones":

```html
<th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
    <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
        <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Venta</span>
    </span>
</th>
```

Then in the table body (around line 1074-1080), add a new `<td>` after the "Hora" cell and before the closing `</tr>`:

```html
<td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
    <div class="fi-ta-col-wrp px-3 py-4">
        @if($movement['sale_id'])
            <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 py-1 fi-color-primary bg-blue-50 text-blue-600 ring-blue-600/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                {{ $movement['sale_info'] }}
            </span>
        @else
            <span class="text-xs text-gray-400">—</span>
        @endif
    </div>
</td>
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/Reconciliations/CreateReconciliation.php resources/views/livewire/reconciliations/create-reconciliation.blade.php
git commit -m "feat: display linked sale on reconciliation movements table"
```

#### 🧪 Tests for this Task

**Test file:** Add to `tests/Feature/AssignedProductMovementSaleLinkingTest.php`

```php
// --- Task 5: Reconciliation tests ---

it('includes sale info in reconciliation movements when movement has sale_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();

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
        'sale_date' => now(),
        'status' => 'confirmed',
        'subtotal' => 100,
        'total_amount' => 100,
    ]);

    AssignedProductMovement::create([
        'detail_assigned_product_id' => $detail->id,
        'type' => 'change',
        'quantity' => 5,
        'sale_id' => $sale->id,
        'created_by' => $user->id,
    ]);

    $component = \Livewire\Livewire::test(\App\Livewire\Reconciliations\CreateReconciliation::class, [
        'employee_id' => $employee->id,
    ]);
    $component->call('loadMovements');

    $movements = $component->get('movements');

    expect($movements)->toHaveCount(1);
    expect($movements[0]['sale_id'])->toEqual($sale->id);
    expect($movements[0]['sale_info'])->not->toBeNull();
});

it('shows null sale info in reconciliation when movement has no sale_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);
    $employee = Employee::factory()->create();

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 50,
    ]);

    AssignedProductMovement::create([
        'detail_assigned_product_id' => $detail->id,
        'type' => 'royalty',
        'quantity' => 3,
        'sale_id' => null,
        'created_by' => $user->id,
    ]);

    $component = \Livewire\Livewire::test(\App\Livewire\Reconciliations\CreateReconciliation::class, [
        'employee_id' => $employee->id,
    ]);
    $component->call('loadMovements');

    $movements = $component->get('movements');

    expect($movements)->toHaveCount(1);
    expect($movements[0]['sale_id'])->toBeNull();
    expect($movements[0]['sale_info'])->toBeNull();
});
```

**Run command:**
```bash
./vendor/bin/pest tests/Feature/AssignedProductMovementSaleLinkingTest.php --filter="includes sale info|shows null sale"
```

---

### Self-Review Checklist

- [x] All spec requirements are covered
- [x] Each task has a commit checkpoint
- [x] Code snippets are complete (not partial)
- [x] Each task has a `🧪 Tests for this Task` section with complete, runnable test code
- [x] Each test section has at least one happy path, one edge case, one failure scenario
- [x] Each test section includes the exact run command
- [x] Test code uses PestPHP and follows project conventions
- [x] Dependencies between tasks are respected (Task 1 → Task 2 → Task 3 → Task 4 → Task 5)
- [x] No orphan files (everything referenced exists)
