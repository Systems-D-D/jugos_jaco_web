# Bug-Fix Report: sale_quantity double-counts changes and royalties in stock

**Date:** 2026-05-12
**Agent:** DevFlow Bug-Fixer 🩹
**Severity:** High

## Bug Report

**Error:** Stock inconsistency — `sale_quantity` accumulate incorrectamente `changes_quantity` y `royalties_quantity` en cada venta, causando doble descuento en el cálculo de stock disponible.

**Steps to reproduce:**
1. Asignar 45 unidades de un producto a un empleado
2. Registrar movimientos: 2 cambios (changes) + 1 regalía (royalty) → `changes_quantity=2`, `royalties_quantity=1`
3. Vender 28 unidades vía API
4. Verificar stock → el sistema muestra 11 en lugar de 14 (45 - 28 - 2 - 1)

## Root Cause

**Affected file:** `app/Services/SaleService.php:156` (antes del fix)
**Affected function:** `createSaleDetails()`

**Causal chain:**
1. Cada vez que se crea una venta, `sale_quantity` se calcula como: `sale_quantity + changes_quantity + royalties_quantity + productData['quantity']`
2. La fórmula de stock en `DetailAssignedProduct.php:56` resta: `quantity - (sale_quantity + returned_quantity + changes_quantity + royalties_quantity)`
3. Como `changes_quantity` y `royalties_quantity` ya están incluidos en `sale_quantity`, se restan **dos veces** contra el stock.

**Root cause (one sentence):** `sale_quantity` acumula `changes_quantity` y `royalties_quantity` en cada operación de venta, y la fórmula de stock los vuelve a restar por separado — doble descuento.

## Reproduction Test

- **Test file:** `tests/Feature/SaleQuantityStockTest.php`
- **Test name:** `it does not double-subtract changes and royalties from stock when a sale is created`
- **Verify reproduction:** `./vendor/bin/pest tests/Feature/SaleQuantityStockTest.php`

## Fix Applied

| File | Line | Change | Before | After |
|------|------|--------|--------|-------|
| `app/Services/SaleService.php` | 167 | Remover `changes_quantity` y `royalties_quantity` del cálculo | `$nSaleQuantity = ($detail->sale_quantity ?? 0) + $detail->changes_quantity + $detail->royalties_quantity + $productData['quantity'];` | `$nSaleQuantity = ($detail->sale_quantity ?? 0) + $productData['quantity'];` |

**Commit:** `fix(sale-service): remove double-counting of changes and royalties from sale_quantity`

## Verification

- **Reproduction test:** `./vendor/bin/pest tests/Feature/SaleQuantityStockTest.php`
- **Full suite:** `php artisan test`

## Pattern (for debug-patterns.md)

| Stack | Error Type | Root Cause Pattern | Fix Strategy |
|-------|------------|-------------------|--------------|
| PHP 8.2 + Laravel 11 | Wrong output (stock mismatch) | Accumulator field includes non-sale deductions that are also subtracted separately | Remove non-sale accumulators from the sale accumulator; let the stock formula handle each deduction type independently |

## Notes

- Los movimientos de regalías y cambios ya se manejan correctamente vía `AssignedProductMovementService` (incrementan `changes_quantity`/`royalties_quantity` en sus campos dedicados).
- La fórmula de stock en `DetailAssignedProduct::stock()` es correcta: agrega todas las deducciones por separado.
- El único punto incorrecto era que `sale_quantity` también absorbía esos valores.
