# Code Review: AssignedProductMovement Sale Linking

**Date:** 2026-05-12
**Reviewer:** DevFlow Reviewer (automated)
**Review Mode:** Cycle
**Invoking Agent:** Implementer
**Reference:** `docs/devflow/specs/2026-05-12-assigned-product-movement-sale-linking-design.md`, `docs/devflow/plans/2026-05-12-assigned-product-movement-sale-linking.md`

## Summary

La implementación sigue fielmente la especificación y el plan. Migración correcta, modelos actualizados, servicios modificados con backward compatibility, validación API agregada, y reconciliación muestra la nueva columna "Venta". Se detectaron 2 WARN preexistentes (no introducidos por esta feature) y 3 INFO.

## Findings

### 🔴 BLOCK (must fix)

*No se encontraron bloqueos.*

### 🟡 WARN (should fix)

| # | File | Line | Issue | Suggestion |
|---|------|------|-------|------------|
| 1 | `app/Http/Controllers/SaleController.php` | 195-196 | Indentación inconsistente en `'discount_amount'` y `'movement_type'` dentro del array `$details[]`. La línea `'discount_amount'` está al mismo nivel que `'line_total'` sin la indentación correcta, y `'movement_type'` también. | Corregir indentación a 16 espacios para alinear con el resto de elementos del array. |
| 2 | `app/Models/AssignedProductMovement.php` | 21 | `'sale_id' => 'integer'` en `$casts` — Eloquent ya maneja `foreignId` como integer automáticamente. El cast explícito es redundante pero no dañino. | Opcional: remover el cast `'sale_id' => 'integer'` pues Eloquent lo maneja nativamente. |

### 🟢 INFO (optional)

| # | File | Line | Issue | Suggestion |
|---|------|------|-------|------------|
| 1 | `app/Services/SaleService.php` | 93 | `'branch_id' => $saleData['branch_id']` se pasa a `Sale::create()` pero `branch_id` no está en `Sale::$fillable` — es silenciosamente ignorado por mass assignment. (Preexistente, no introducido por esta feature) | Agregar `'branch_id'` al `$fillable` del modelo `Sale` o removerlo de `SaleService`. |
| 2 | `app/Services/AssignedProductMovementService.php` | 5 | `use App\Models\AssignedProduct;` está importado pero nunca se usa en este archivo. | Remover la importación no utilizada. |
| 3 | `app/Models/Sale.php` | 241 | `getPaymentTypeValueAttribute()` referencia `$this->payment_type` pero la columna se renombró a `payment_method` en migración `2025_08_19_213316`. (Preexistente) | Cambiar `$this->payment_type` a `$this->payment_method`. |

## Spec/Plan Alignment

| Requisito | Estado |
|-----------|--------|
| Migración `sale_id` FK nullable con `nullOnDelete()` | ✅ |
| `AssignedProductMovement` tiene `sale()` BelongsTo | ✅ |
| `Sale` tiene `assignedProductMovements()` HasMany | ✅ |
| `AssignedProductMovementService::createMovement()` acepta `$saleId` opcional | ✅ |
| `SaleService::calculateTotals()` excluye productos con `movement_type` | ✅ |
| `SaleService::createSaleDetails()` bifurca: movement_type → AssignedProductMovement | ✅ |
| `createMovementFromSaleProduct()` busca `DetailAssignedProduct` vía `AssignedProduct` | ✅ |
| `SaleRequest` valida `products.*.movement_type` con Enum | ✅ |
| `SaleController::prepareSaleDetailsData()` pasa `movement_type` | ✅ |
| `SaleController` constructor actualizado con 4to parámetro | ✅ |
| `CreateReconciliation::loadMovements()` eager-load `sale` + `sale_id`/`sale_info` | ✅ |
| Blade: columna "Venta" con badge azul cuando `sale_id` existe, "—" cuando no | ✅ |
| Nested transactions manejados (savepoints de Laravel) | ✅ |
| Creación standalone de movimientos (sin `saleId`) sigue funcionando | ✅ |
| Productos royalty/changes no afectan totales de venta | ✅ |
| Tests: 4 Unit + 16 Feature cubriendo todos los escenarios | ✅ |

## Verdict

✅ **APPROVED** — no blockers. 2 WARN (1 indentación menor, 1 cast redundante) y 3 INFO (bugs preexistentes). La feature está lista para Fase 7 (Finalizer).
