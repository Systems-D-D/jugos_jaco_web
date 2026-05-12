# Design Spec: AssignedProductMovement Sale Linking

**Date:** 2026-05-12
**Slug:** assigned-product-movement-sale-linking
**Feature Type:** backend

---

## Context

Actualmente los `AssignedProductMovement` (cambios y regalías) se crean de forma independiente mediante API o en la reconciliación, sin trazabilidad a una venta. Se requiere que al crear una venta mediante `SaleService`, los productos marcados con `movementType` = `royalty` o `changes` no se registren en `SaleDetail`, sino como `AssignedProductMovement` vinculados a la venta mediante un nuevo FK `sale_id` (nullable). La creación standalone de movimientos (sin venta) debe seguir funcionando.

---

## Architecture

### Data Flow

```
POST /api/sales  (SaleRequest con movement_type por producto)
  │
  ├─ SaleController::createSale()
  │    ├─ SaleController::prepareSaleDetailsData()
  │    │    └─ Pasa movement_type del request al productData
  │    └─ SaleService::createSale($saleData, $productsData)
  │         ├─ calculateTotals() — excluye productos con movement_type
  │         ├─ Sale::create() — totales no incluyen royalty/changes
  │         ├─ createSaleDetails()
  │         │    ├─ movement_type = null  → SaleDetail::create() (flujo normal)
  │         │    └─ movement_type != null → Busca DetailAssignedProduct
  │         │                               → AssignedProductMovementService::createMovement()
  │         │                                 con sale_id = $sale->id
  │         ├─ AccountReceivable (si crédito)
  │         └─ ClientVisit
  │
  └─ Sale retornado con ->fresh(['client','employee','details'])
```

### Component Map

| Component | Change Type | Purpose |
|-----------|-------------|---------|
| `assigned_product_movements` (migration) | **New** | Agrega `sale_id` FK nullable |
| `AssignedProductMovement` (model) | **Modified** | Agrega fillable + relación `sale()` |
| `Sale` (model) | **Modified** | Agrega relación `assignedProductMovements()` |
| `AssignedProductMovementService` | **Modified** | `createMovement()` acepta `?int $saleId` |
| `SaleService` | **Modified** | Constructor inyecta MovementService; `calculateTotals()` excluye royalty/changes; `createSaleDetails()` bifurca según movement_type |
| `SaleController` | **Modified** | `prepareSaleDetailsData()` pasa `movement_type` |
| `SaleRequest` | **Modified** | Valida `products.*.movement_type` como enum |
| `CreateReconciliation` (Livewire) | **Modified** | `loadMovements()` incluye `sale` eager load y nueva columna |
| `create-reconciliation.blade.php` | **Modified** | Agrega columna "Venta" en tabla de movimientos |

---

## Data Structures

### Migration: `add_sale_id_to_assigned_product_movements`

```php
Schema::table('assigned_product_movements', function (Blueprint $table) {
    $table->foreignId('sale_id')->nullable()->after('detail_assigned_product_id')
          ->constrained('sales')->nullOnDelete();
});
```

- `nullOnDelete()` — si la venta se elimina, el movimiento permanece con `sale_id = null`.
- Columna posicionada después de `detail_assigned_product_id` por proximidad lógica a FKs.

### AssignedProductMovement Model Changes

```php
// Add to $fillable
'sale_id',

// Add to $casts
'sale_id' => 'integer',

// Add relationship
public function sale(): BelongsTo
{
    return $this->belongsTo(Sale::class);
}
```

### Sale Model Changes

```php
public function assignedProductMovements(): HasMany
{
    return $this->hasMany(AssignedProductMovement::class);
}
```

### AssignedProductMovementService Changes

```php
public function createMovement(
    int $detailId,
    string $type,
    float $quantity,
    ?string $note = null,
    ?int $saleId = null      // NEW parameter
): AssignedProductMovement
{
    return DB::transaction(function () use ($detailId, $type, $quantity, $note, $saleId) {
        // ... existing validation ...

        $movement = AssignedProductMovement::create([
            'detail_assigned_product_id' => $detail->id,
            'type' => AssignedProductMovementTypeEnum::from($type),
            'quantity' => $quantity,
            'note' => $note,
            'sale_id' => $saleId,          // NEW field
            'created_by' => auth()->id(),
        ]);

        // ... existing accumulator update ...
        return $movement;
    });
}
```

### SaleService Changes

**Constructor** — agregar inyección:

```php
protected $assignedProductMovementService;

public function __construct(
    ManagementInventoryService $managementInventoryService,
    AccountReceivableService $accountReceivableService,
    ClientVisitService $clientVisitService,
    AssignedProductMovementService $assignedProductMovementService  // NEW
) {
    // ... existing assignments ...
    $this->assignedProductMovementService = $assignedProductMovementService;
}
```

**`calculateTotals()`** — excluir productos con `movement_type`:

```php
public function calculateTotals(array $products): array
{
    $subtotal = 0;
    $totalTaxes = 0;

    foreach ($products as $product) {
        // Skip royalty/changes products — they don't affect sale totals
        if (!empty($product['movement_type'])) {
            continue;
        }

        $lineSubtotal = $product['line_subtotal'] ??
            ($product['quantity'] * $product['unit_price_without_tax']);
        $lineTaxAmount = $product['line_tax_amount'] ??
            ($product['quantity'] * ($product['unit_tax_amount'] ?? 0));
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

**`createSaleDetails()`** — bifurcar según `movement_type`:

```php
protected function createSaleDetails(Sale $sale, array $productsData): void
{
    foreach ($productsData as $productData) {
        // --- CASE 1: Royalty or Change → AssignedProductMovement ---
        if (!empty($productData['movement_type'])) {
            $this->createMovementFromSaleProduct($sale, $productData);
            continue;
        }

        // --- CASE 2: Normal product → SaleDetail (existing logic) ---
        // ... existing code unchanged ...
    }
}
```

**New private method `createMovementFromSaleProduct()`**:

```php
private function createMovementFromSaleProduct(Sale $sale, array $productData): void
{
    $assignedProduct = AssignedProduct::where('employee_id', $sale->employee_id)
        ->whereDate('date', $sale->sale_date)
        ->first();

    if (!$assignedProduct) {
        throw new Exception(
            "No hay asignación de productos para el empleado en la fecha {$sale->sale_date}."
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

    $note = $productData['note'] ?? "Venta #INV-{$sale->id}";

    $this->assignedProductMovementService->createMovement(
        detailId: $detail->id,
        type: $productData['movement_type'],
        quantity: $productData['quantity'],
        note: $note,
        saleId: $sale->id,
    );
}
```

### SaleController Changes

En `prepareSaleDetailsData()`, pasar `movement_type`:

```php
$details[] = [
    // ... existing fields ...
    'movement_type' => $product['movement_type'] ?? null,   // NEW
];
```

### SaleRequest Changes

Agregar validación:

```php
'products.*.movement_type' => ['nullable', 'string', new Enum(AssignedProductMovementTypeEnum::class)],
```

### Reconciliation Changes

**`CreateReconciliation::loadMovements()`** — agregar eager load de `sale`:

```php
$this->movements = AssignedProductMovement::whereHas('detailAssignedProduct', function ($query) use ($assignedProduct) {
        $query->where('assigned_products_id', $assignedProduct->id);
    })
    ->with(['detailAssignedProduct.product', 'sale'])   // ADDED 'sale'
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
            'sale_id' => $movement->sale_id,                                        // NEW
            'sale_info' => $movement->sale?->full_invoice_number ?? $movement->sale_id,  // NEW
        ];
    })->toArray();
```

**Blade** — agregar columna "Venta" después de "Hora":

```html
<th><!-- Venta --></th>
...
<td>
    @if($movement['sale_id'])
        <span class="text-xs text-blue-600">{{ $movement['sale_info'] }}</span>
    @else
        <span class="text-xs text-gray-400">—</span>
    @endif
</td>
```

---

## Reusability Decisions

| Existing component | Current purpose | Reusable for | Decision | Justification |
|--------------------|-----------------|--------------|----------|---------------|
| `AssignedProductMovementTypeEnum` | Define tipos de movimiento (CHANGE, ROYALTY) | `movementType` en productData | **Reuse** | Mismos valores semánticos, evita crear un enum duplicado |
| `AssignedProductMovementService::createMovement()` | Crear movimientos standalone | Crear movimientos vinculados a venta | **Extend** (nuevo parámetro) | Misma lógica de validación y acumuladores, solo se agrega `saleId` |
| `SaleService::calculateTotals()` | Sumar totales de productos | Excluir productos royalty/changes | **Modify** | Agregar filtro `movement_type` |
| `SaleService::createSaleDetails()` | Crear SaleDetail por producto | Bifurcar a AssignedProductMovement | **Modify** | Agregar rama condicional |
| `AssignedProduct` (model) | Asignación diaria por empleado | Buscar DetailAssignedProduct desde SaleService | **Query** | Ya existe la relación; solo se consulta |

---

## Test Architecture

### Test Runner
- **Framework:** PestPHP
- **Command:** `php artisan test` o `./vendor/bin/pest`
- **Single file:** `./vendor/bin/pest {file}`
- **Test root:** `tests/`
- **Suites:** `Unit` (tests/Unit), `Feature` (tests/Feature)

### Factories Needed
No existen factories para modelos de dominio. Se crearán según necesidad:

| Factory | Model | Campos mínimos |
|---------|-------|---------------|
| `EmployeeFactory` | `Employee` | `first_name`, `last_name`, `branch_id` |
| `AssignedProductFactory` | `AssignedProduct` | `employee_id`, `date` |
| `DetailAssignedProductFactory` | `DetailAssignedProduct` | `assigned_products_id`, `product_id`, `quantity` |
| `SaleFactory` | `Sale` | `client_id`, `employee_id`, `sale_date`, `status`, `total_amount`, `subtotal` |
| `ProductFactory` | `Product` | `name`, `code`, `is_active` |

### Test Cases (Feature)

| # | Test | Type | Description |
|---|------|------|-------------|
| 1 | `it excludes royalty products from sale totals` | Feature | Verifica que `calculateTotals()` ignora productos con `movement_type='royalty'` |
| 2 | `it excludes change products from sale totals` | Feature | Verifica que `calculateTotals()` ignora productos con `movement_type='change'` |
| 3 | `it creates AssignedProductMovement with sale_id for royalty product` | Feature | Al crear venta con `movement_type='royalty'`, se crea movimiento vinculado |
| 4 | `it creates AssignedProductMovement with sale_id for change product` | Feature | Al crear venta con `movement_type='change'`, se crea movimiento vinculado |
| 5 | `it does not create SaleDetail for royalty product` | Feature | Producto con `movement_type='royalty'` no genera SaleDetail |
| 6 | `it creates SaleDetail for normal products` | Feature | Producto sin `movement_type` sigue creando SaleDetail normalmente |
| 7 | `it throws exception when no AssignedProduct exists for employee` | Feature | Si no hay asignación, lanza error descriptivo |
| 8 | `it throws exception when product not in assignment` | Feature | Si el producto no está asignado al empleado, lanza error |
| 9 | `it creates standalone movement without sale_id` | Feature | `createMovement()` sin `saleId` funciona como antes |
| 10 | `it creates movement with sale_id when saleId provided` | Feature | `createMovement()` con `saleId` guarda la relación |

### Test Cases (Unit)

| # | Test | Type | Description |
|---|------|------|-------------|
| 11 | `AssignedProductMovement has sale relationship` | Unit | Verifica que `sale()` retorna BelongsTo |
| 12 | `Sale has assignedProductMovements relationship` | Unit | Verifica que `assignedProductMovements()` retorna HasMany |

---

## API Contract

No new endpoints. Solo se modifica el request body de `POST /api/sales`.

### Modified: `POST /api/sales`

| Field | Value |
|-------|-------|
| Method | POST |
| Path | `/api/sales` |
| Auth | Laravel Sanctum |

**Request Body** (nuevo campo en products):

```json
{
  "client_id": 1,
  "employee_id": 5,
  "payment_term": "cash",
  "payment_method": "cash",
  "cash_amount": 500.00,
  "products": [
    {
      "product_id": 10,
      "product_price_id": 25,
      "quantity": 2,
      "movement_type": null
    },
    {
      "product_id": 15,
      "product_price_id": 30,
      "quantity": 1,
      "movement_type": "royalty"
    },
    {
      "product_id": 12,
      "product_price_id": 28,
      "quantity": 3,
      "movement_type": "change"
    }
  ]
}
```

- `products.*.movement_type`: `null` (normal), `"royalty"` (regalía), `"change"` (cambio)
- Si es `null` o no se envía → comportamiento actual (SaleDetail)
- Si es `"royalty"` o `"change"` → AssignedProductMovement con `sale_id`

**Response** (sin cambios):
```json
{
  "success": true,
  "message": "Venta #INV-42 creada con éxito",
  "data": 42
}
```

---

## Risk Assessment

| Risk | Level | Mitigation |
|------|-------|------------|
| Nested DB transactions causan deadlocks | 🟢 LOW | Laravel maneja nesting con savepoints; `createMovement()` ya usa transacciones y es llamado desde `createSale()` que también las usa — probado en producción con el mismo patrón |
| Producto con movement_type pero sin DetailAssignedProduct | 🟡 MEDIUM | Se valida existencia y se lanza Exception con mensaje descriptivo; `SaleService` hace rollback |
| Sale se elimina y movimientos quedan huérfanos | 🟢 LOW | `nullOnDelete()` — el movimiento se preserva, `sale_id` queda null; el negocio requiere trazabilidad histórica |
| Cambios en `prepareSaleDetailsData()` rompen el flujo Livewire | 🟢 LOW | Livewire NO usa `SaleService` ni `prepareSaleDetailsData()` — flujos independientes |
| `movement_type` se envía con valor inválido desde API | 🟢 LOW | `SaleRequest` valida con `Enum` rule antes de llegar al servicio |

---

## Rollback Strategy

1. **Migration rollback:** `php artisan migrate:rollback --step=1` (solo remueve la columna `sale_id`)
2. **Código:** Revertir commits del feature branch
3. **Datos:** Los `AssignedProductMovement` creados con `sale_id` seguirán existiendo si la columna se elimina (MySQL ignora columnas extra en INSERTs antiguos). Si se requiere limpiar, truncar movimientos creados en el período.
4. **Verificación:** `POST /api/sales` sin `movement_type` debe comportarse idéntico a antes del cambio.

---

## Design Decisions

| Decision | Alternatives | Reasoning |
|----------|-------------|-----------|
| Usar `AssignedProductMovementTypeEnum` existente para `movement_type` | Crear nuevo enum (`MovementTypeEnum`) | El enum existente tiene exactamente los mismos valores (change, royalty); duplicar violaría DRY |
| `nullOnDelete()` en FK `sale_id` | `cascadeOnDelete()` o `restrictOnDelete()` | `nullOnDelete` preserva el historial de movimientos; `cascade` eliminaría datos de negocio; `restrict` bloquearía eliminación de ventas |
| `movement_type` como campo en `productData` (no parámetro separado) | Array separado `movementsData` | Cada producto individualmente puede ser normal, royalty o change; agrupar por tipo complicaría el mapeo y validación |
| Validar existencia de `AssignedProduct` + `DetailAssignedProduct` en `SaleService` | Validar en `AssignedProductMovementService` | El contexto de venta (employee_id, fecha) pertenece al flujo de venta; el servicio de movimientos recibe `detailId` ya resuelto |
| Nota del movimiento: `"Venta #INV-{id}"` | Sin nota o nota personalizada | Trazabilidad: el operador puede identificar el origen del movimiento en la reconciliación |
| Inyectar `AssignedProductMovementService` en constructor de `SaleService` | Resolver con `app()` o `resolve()` inline | Sigue el patrón existente del proyecto (inyección por constructor) y facilita testing |

---

## Constraints

- Solo se modifica el flujo API (`SaleService` + `SaleController`). El flujo Livewire (`Livewire/Sales/CreateSale.php`) no se toca.
- La creación standalone de movimientos (`POST /api/product-movements`) no se modifica — `saleId` es opcional.
- Los totales de venta NO incluyen productos royalty/changes.
- Backward compatibility: requests sin `movement_type` se comportan idéntico a antes.
- Se reutiliza `AssignedProductMovementTypeEnum` existente.
- Tests con PestPHP requeridos.
