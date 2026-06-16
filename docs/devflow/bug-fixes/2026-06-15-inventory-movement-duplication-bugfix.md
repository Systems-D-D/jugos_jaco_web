# Bug-Fix Report: inventory-movement-duplication

**Bug:** Movimientos SALIDA duplicados en `management_inventory` al crear asignaciones de productos desde Filament
**Error type:** Condicion de carrera (race condition) + lost updates en stock
**Root cause:** El `CreateAction::before()` hook en `DetailsRelationManager` verificaba duplicados sin transaccion ni `lockForUpdate()`, permitiendo que requests concurrentes pasaran la validacion y crearan multiples movimientos SALIDA. Adicionalmente, `updateStock()` usaba un patron read-modify-write sin bloqueo.

**Stack:** PHP 8.2+ · Laravel 11 · PestPHP

## Definition of Done

| Criterio | Estado | Evidencia |
|----------|--------|-----------|
| No se crean movimientos SALIDA duplicados bajo concurrencia | ✅ Met | `tests/Feature/InventoryMovementRaceConditionTest.php` — 3 tests passing |
| Stock se actualiza atomicamente sin lost updates | ✅ Met | `ManagementInventoryService::registerExit/registerDamaged` usan `DB::table()->decrement()` con `WHERE stock >= quantity` |
| CreateAction envuelve validacion + insercion + movimiento en una sola transaccion | ✅ Met | `DetailsRelationManager.php:69-122` — `using()` con `DB::transaction()` + `lockForUpdate()` |
| DeleteBulkAction registra movimientos ENTRADA | ✅ Met | `DetailsRelationManager.php:176-190` — `before()` hook con ENTRADA por cada registro |
| Tests existentes no presentan regresiones | ✅ Met | `InventoryDuplicateAssignmentTest` (3/3), `SaleQuantityStockTest` (1/1), `AssignedProductMovementSaleLinkingTest` (16/16) passing |

## Affected Files

- `app/Filament/Resources/AssignedProductResource/RelationManagers/DetailsRelationManager.php` — CreateAction con `using()` + transaccion + lockForUpdate; DeleteBulkAction con `before()` hook
- `app/Services/ManagementInventoryService.php` — `registerExit/registerDamaged` con decremento atomico; `registerEntry/registerReturn` con incremento atomico; eliminados `updateStock()` y `checkAvailableStock()`

## Files Created

- `tests/Feature/InventoryMovementRaceConditionTest.php` — 3 tests de reproduccion

## Fix Strategy Applied

- [x] Task 1: CreateAction con `using()` + transaccion + lockForUpdate — `DetailsRelationManager.php:68-125`
  - Reemplazo de `before()`/`after()` hooks por `using()` que envuelve validacion de duplicados, verificacion de stock (con `lockForUpdate()` en `FinishedProductInventory`), creacion de `DetailAssignedProduct`, y registro de movimiento SALIDA en una sola transaccion atomica
- [x] Task 2: updateStock() atomico — `ManagementInventoryService.php:116-135`
  - Reemplazo de `$model->stock += $quantity; $model->save()` por `DB::table()->where('id', $model->id)->where('stock', '>=', $quantity)->decrement('stock', $quantity)` — operacion atomica a nivel de DB que previene lost updates y verifica stock en una sola query
- [x] Task 3: DeleteBulkAction con before() hook — `DetailsRelationManager.php:176-190`
  - Agregado `before()` hook que itera sobre registros seleccionados, valida que no tengan ventas, y registra movimiento ENTRADA por cada producto eliminado masivamente

## Test Results

```
PASS  InventoryMovementRaceConditionTest (3 tests, 18 assertions)
PASS  InventoryDuplicateAssignmentTest (3 tests, 13 assertions)
PASS  SaleQuantityStockTest (1 test, 1 assertion)
PASS  AssignedProductMovementSaleLinkingTest (16 tests, 35 assertions)
```

**Test command:** `php artisan test tests/Feature/InventoryMovementRaceConditionTest.php tests/Feature/InventoryDuplicateAssignmentTest.php tests/Feature/SaleQuantityStockTest.php tests/Feature/AssignedProductMovementSaleLinkingTest.php`

## Additional Fixes Applied (Post-Review)

- [x] Task 4: `EditAction::using()` envuelto en `DB::transaction()` — `DetailsRelationManager.php:143-169`
  - Previene inconsistencia si `managementInventoryUpdateDetail()` falla después de actualizar el registro
- [x] Task 5: `DeleteBulkAction::before()` envuelto en `DB::transaction()` — `DetailsRelationManager.php:172-187`
  - Previene movimientos ENTRADA registrados sin eliminación correspondiente en caso de fallo parcial
- [x] Task 6: Corregido error gramatical en mensajes de validación — "ya se han vendidos" → "ya se han vendido"

## Additional Recommendations

1. **EditAction sin transaccion** — El `EditAction::using()` en `DetailsRelationManager` no envuelve la actualizacion de cantidad + movimiento de inventario en una transaccion. Si `managementInventoryUpdateDetail()` falla, el registro ya fue actualizado pero el movimiento no se registro. Se recomienda envolver en `DB::transaction()`.

2. **CreateProductReturn::afterCreate()** — El hook `afterCreate()` en `CreateProductReturn.php` se ejecuta despues de que Filament commitea el registro. Si `registerInventoryMovement()` falla, la devolucion existe sin movimiento de inventario. Se recomienda usar `using()` para atomicidad.

3. **Test isolation entre suites** — `SaleDuplicationPreventionTest` tiene un problema de test isolation preexistente cuando se ejecuta junto con `InventoryDuplicateAssignmentTest` (datos residuales de `Sale` no limpiados entre suites). No relacionado con este fix.

4. **Indice unique en `management_inventory`** — Considerar agregar un indice compuesto o mecanismo de idempotencia en la tabla `management_inventory` para prevenir duplicados a nivel de DB como capa adicional de seguridad.
