# Feature Summary: product-shortage-cash-factor

**Date:** 2026-05-14
**Slug:** product-shortage-cash-factor
**Branch:** `feature/product-shortage-cash-factor`

## What was built

Se agregó al proceso de cuadre (reconciliación) el cálculo del valor monetario de los productos sobrantes usando una escala de precios (TypePrice) seleccionable por el usuario. El total se suma al Efectivo Esperado que el empleado debe entregar.

## Changes

### Database
- Migration: columnas `product_shortage_total` (decimal) y `type_price_id` (FK nullable) en `daily_sales_reconciliations`

### Backend
- `DailySalesReconciliation` model: fillable, casts, relación `typePrice()`
- `CreateReconciliation` Livewire: selector TypePrice, cálculo `Sobrante x Precio`, integración con totales
- `ProductUnit` model: agregado `HasFactory` trait
- 4 nuevas factories: `Unit`, `ProductUnit`, `ProductPrice`, `DailySalesReconciliation`

### Frontend
- Blade `create-reconciliation.blade.php`:
  - Selector TypePrice en encabezado de Productos Sobrantes
  - Columna "Efectivo Prod. Faltante" (= Sobrante x Precio)
  - Badge de código en nombre de producto (columna Código eliminada)
  - Total faltante arriba de Efectivo Esperado (sumado)
  - Altura de tabla igualada a Ventas del Día (225px)

### View
- `ViewDailySalesReconciliation`: muestra `product_shortage_total` y `TypePrice` en sección de Análisis

### Tests
- 8 tests en `tests/Feature/DailySalesReconciliationProductShortageTest.php`:
  - Cálculo correcto
  - Recalculo al cambiar TypePrice
  - Total incluido en Efectivo Esperado
  - Persistencia al guardar
  - Edge cases: sin TypePrice, sin sobrantes, modelo con datos nulos

## How to test

```bash
php artisan test --filter=DailySalesReconciliationProductShortageTest
```

## Artifacts

| Type | Path |
|------|------|
| Spec | `.opencode/plans/product-shortage-cash-factor-design.md` |
| Plan | `.opencode/plans/product-shortage-cash-factor-plan.md` |
| Review | `.opencode/plans/product-shortage-cash-factor-review.md` |
| Summary | `.opencode/plans/product-shortage-cash-factor-feature.md` |

## Commits (7)

```
d168d83 feat: add product_shortage_total and type_price_id to daily_sales_reconciliations
b5fb290 feat: add typePrice relation and product_shortage_total to reconciliation model
63d1a10 feat: add product shortage cash calculation to reconciliation Livewire component
f7b42be feat: add TypePrice selector, shortage cash column, and code badge to Productos Sobrantes
df30906 feat: display product shortage cash total above expected cash in reconciliation
da2d990 feat: display product shortage cash and type price in reconciliation view
80490b9 fix: use array_map for Livewire dirty detection, accessibility labels, and native Livewire::test()
75d5299 test: use Livewire::test() native and model assertions for view tests
0c9cdce fix: authenticate user before Livewire::test() in reconciliation tests
```
