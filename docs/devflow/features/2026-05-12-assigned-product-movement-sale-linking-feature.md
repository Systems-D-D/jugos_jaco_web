## 🚀 DevFlow Finalization — AssignedProductMovement Sale Linking

### ✅ Definition of Done
| Criterion | Status |
|-----------|--------|
| Migración agrega `sale_id` nullable FK a `assigned_product_movements` | ✅ Met |
| `AssignedProductMovement` tiene relación `sale()` belongsTo | ✅ Met |
| `Sale` tiene relación `assignedProductMovements()` hasMany | ✅ Met |
| `SaleService` procesa `movementType`: royalty/changes → AssignedProductMovement con `sale_id`; `null` → SaleDetail normal | ✅ Met |
| Creación standalone de AssignedProductMovement (sin venta) sigue funcionando | ✅ Met |
| Productos royalty/changes no afectan totales de la venta | ✅ Met |
| Reconciliation muestra la relación con Sale cuando `sale_id` existe | ✅ Met |
| Tests pasan (PestPHP) | ✅ 16/16 passing |

### ✅ Tests
16 tests passing | 0 failing | All in `tests/Feature/AssignedProductMovementSaleLinkingTest.php`

### 📦 Files Changed
**Created:**
- `database/migrations/2026_05_12_124133_add_sale_id_to_assigned_product_movements_table.php`
- `database/factories/BranchFactory.php`
- `database/factories/CategoryFactory.php`
- `database/factories/TypePriceFactory.php`
- `tests/Feature/AssignedProductMovementSaleLinkingTest.php`

**Modified:**
- `app/Models/AssignedProductMovement.php` — +fillable `sale_id`, +cast, +`sale()` relation
- `app/Models/Sale.php` — +`assignedProductMovements()` relation
- `app/Services/AssignedProductMovementService.php` — +`$saleId` param
- `app/Services/SaleService.php` — +MovementService injection, +`calculateTotals()` filter, +`createSaleDetails()` bifurcation, +`createMovementFromSaleProduct()`
- `app/Http/Controllers/SaleController.php` — constructor + MovementService, +`movement_type` pass-through
- `app/Http/Requests/SaleRequest.php` — +validation `products.*.movement_type`
- `app/Livewire/Reconciliations/CreateReconciliation.php` — +`sale` eager load, +`sale_id`/`sale_info`
- `resources/views/livewire/reconciliations/create-reconciliation.blade.php` — +columna "Venta"
- `database/factories/UserFactory.php` — +`employee_id`
- `database/factories/EmployeeFactory.php` — campos completos + Branch ref
- `database/factories/ProductFactory.php` — campos completos + Category ref
- `database/factories/ClientFactory.php` — campos completos
- `database/factories/SaleFactory.php` — `created_by`/`updated_by` desde UserFactory
- `database/factories/AssignedProductFactory.php` — Employee ref
- `database/factories/DetailAssignedProductFactory.php` — AssignedProduct + Product refs

### 🧪 Tests Added
| Test | Verifica |
|------|----------|
| creates movement without sale_id | Backward compat: `saleId=null` |
| creates movement with sale_id | Nuevo: `saleId` se persiste |
| throws exception when detail does not exist | Error handling |
| excludes royalty/changes from totals (x3) | `calculateTotals()` filtra |
| creates AssignedProductMovement instead of SaleDetail | `createSale()` bifurca → movimiento |
| throws exception when no assigned product / product not in assignment (x2) | Validación de asignación |
| creates SaleDetail + movements mixed | Flujo combinado normal + royalty |
| validates movement_type enum (x4) | `SaleRequest` validation |
| reconciliation shows sale info (x2) | Columna "Venta" en cuadre |

### 🏗️ Architecture Decisions
- **Enum existente:** `AssignedProductMovementTypeEnum` (`change`, `royalty`) reutilizado para `movement_type`
- **FK `nullOnDelete()`:** Si se elimina una venta, el movimiento conserva el historial (`sale_id` → null)
- **Totales:** `calculateTotals()` usa `!empty($product['movement_type'])` para excluir
- **Transacciones anidadas:** `SaleService` + `AssignedProductMovementService` ambas usan `DB::transaction()`; Laravel maneja nesting con savepoints
- **Nota automática:** Movimientos vinculados a venta tienen nota `"Venta #INV-{id}"`

### ▶️ How to Run / Test
```bash
# Todos los tests de la feature
./vendor/bin/pest tests/Feature/AssignedProductMovementSaleLinkingTest.php

# Test suite completo
php artisan test

# Endpoint API (ejemplo con movement_type)
curl -X POST /api/sales \
  -H "Authorization: Bearer TOKEN" \
  -d '{"client_id":1,"employee_id":5,"payment_term":"cash","payment_method":"cash","cash_amount":100,"products":[{"product_id":10,"product_price_id":25,"quantity":2,"movement_type":"royalty"}]}'
```

### 📚 Documentation
- API: `POST /api/sales` ahora acepta `products.*.movement_type` (nullable, enum: `change`|`royalty`)
- Reconciliation: Nueva columna "Venta" en tabla de movimientos con badge azul
- Backward compatible: requests sin `movement_type` funcionan idéntico a antes

### 💡 Next Steps
- _As an admin, I want to see AssignedProductMovements linked to a sale in the Sale detail page. (Est: S)_
- _As an employee, I want to filter movements by sale in the reconciliation view. (Est: S)_
- _[LOW] Fix pre-existing bugs: `branch_id` not in Sale::$fillable, `payment_type` → `payment_method` accessor mismatch_

### 📄 Artifacts
- Spec:   `docs/devflow/specs/2026-05-12-assigned-product-movement-sale-linking-design.md`
- Plan:   `docs/devflow/plans/2026-05-12-assigned-product-movement-sale-linking.md` ✔️
- Review: `docs/devflow/reviews/2026-05-12-assigned-product-movement-sale-linking-review.md`
- Debug:  `docs/devflow/debug-logs/2026-05-12-assigned-product-movement-sale-linking-debug.md`
