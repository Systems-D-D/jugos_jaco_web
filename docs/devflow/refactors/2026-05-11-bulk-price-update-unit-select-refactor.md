# Refactor Report: Bulk Price Update — Unit Select from Unit model

**Date:** 2026-05-11
**Agent:** DevFlow Refactorer

## Changes Applied

**File modified:** `app/Filament/Resources/ProductPriceResource/Pages/ListProductPrices.php`

| Change | Before | After |
|--------|--------|-------|
| Import | `use App\Models\ProductUnit` | `use App\Models\Unit` |
| Select field name | `product_unit_id` | `unit_id` |
| Options source | `ProductUnit::whereHas('product', ...)` with `'unit.name (conversion_factor)'` label | `Unit::whereHas('productUnits.product', ...)` with `pluck('name', 'id')` |
| Category reset | `$set('product_unit_id', null)` | `$set('unit_id', null)` |
| Query filter | `->where('product_unit_id', $data['product_unit_id'])` | `->whereHas('productUnit', fn($q) => $q->where('unit_id', $data['unit_id']))` |

## Verification

To verify no behavior changed:
1. Open Productos > Precios De Productos
2. Click "Actualizar Precios Masivamente"
3. Confirm "Unidad de Medida" shows unit names (e.g., "Litro") instead of "Litro (1.00)"
4. Select a category and confirm options are filtered
5. Submit and verify notification shows correct count
6. Check a ProductPrice record to confirm the price was updated

## Risks Mitigated
- Field name change (`product_unit_id` → `unit_id`) was applied consistently across all three references: category reset, select definition, and action handler
- No database changes — purely a query-level refactor
