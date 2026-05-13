# Code Review: sale_quantity double-count fix

**Date:** 2026-05-12
**Reviewer:** DevFlow Reviewer (automated)
**Review Mode:** Standalone
**Invoking Agent:** Bug-Fixer
**Reference:** `docs/devflow/bug-fixes/2026-05-12-sale-quantity-double-count-bugfix.md`

## Summary

The fix correctly removes `changes_quantity` and `royalties_quantity` from the `sale_quantity` accumulator, eliminating the double-subtraction in the stock formula. The change is minimal (one line), focused, and the reproduction test validates the corrected behavior. No blockers found.

## Findings

### 🔴 BLOCK (must fix)
*None.*

### 🟡 WARN (should fix)

| # | File | Line | Issue | Suggestion |
|---|------|------|-------|------------|
| 1 | `app/Services/SaleService.php` | 157 | `changes_quantity` y `royalties_quantity` ya no se usan en el cálculo de `nSaleQuantity` (línea 167) pero siguen en el `select()`, generando transferencia de datos innecesaria desde la BD | Simplificar el `select()` a solo las columnas necesarias: `id`, `product_id`, `sale_quantity`, `assigned_products_id`, `quantity` |

### 🟢 INFO (optional)

| # | File | Line | Issue | Suggestion |
|---|------|------|-------|------------|
| 1 | `app/Services/SaleService.php` | 169 | La validación `$detail->quantity < $nSaleQuantity` compara contra `quantity` en vez de `$detail->stock`, lo que no descuenta cambios/regalías/devoluciones al validar stock disponible. (Pre-existente, no introducido por este fix) | Cambiar a `$detail->stock < $productData['quantity']` para validar contra stock real disponible |
| 2 | `tests/Feature/SaleQuantityStockTest.php` | 52 | El test cubre un solo escenario (28 venta + changes=2 + royalties=1). Podría extenderse a más casos borde | Agregar tests para: sin cambios ni regalías, múltiples ventas acumuladas, y el path de `movement_type` |

## Verdict

✅ **APPROVED** — no blockers. The fix is correct, minimal, and validated by a reproduction test.
