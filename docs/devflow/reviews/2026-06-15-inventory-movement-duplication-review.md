# Code Review: inventory-movement-duplication

**Review Mode:** Standalone (invoked by Bug-Fixer)
**Reference:** `docs/devflow/bug-fixes/2026-06-15-inventory-movement-duplication-bugfix.md`
**Date:** 2026-06-15
**Reviewer:** DevFlow Reviewer

---

## Summary

| Metric | Count |
|--------|-------|
| 🔴 BLOCK | 2 |
| 🟡 WARN | 4 |
| 🟢 INFO | 3 |

**Verdict:** ✅ **APPROVED** — Todos los blockers resueltos

### Fixes Aplicados (2026-06-15)

| # | Fix | Archivo |
|---|-----|---------|
| 1 | `EditAction::using()` envuelto en `DB::transaction()` | `DetailsRelationManager.php:143-169` |
| 2 | `DeleteBulkAction::before()` envuelto en `DB::transaction()` | `DetailsRelationManager.php:172-187` |
| 3 | Corregido error gramatical "ya se han vendidos" → "ya se han vendido" | `DetailsRelationManager.php:132, 135, 177` |

---

---

## Módulo 1: ManagementInventoryService

**Archivo:** `app/Services/ManagementInventoryService.php`

### Hallazgos

#### 🟡 WARN-1: Código duplicado en verificación de stock insuficiente
- **File:** `ManagementInventoryService.php:121-124` y `157-160`
- **Standard:** `solid.md §1` (DRY - Don't Repeat Yourself)
- **Issue:** El bloque de verificación de stock insuficiente está duplicado en `registerExit()` y `registerDamaged()`:
  ```php
  if ($affected === 0) {
      $currentStock = DB::table($model->getTable())->where('id', $model->id)->value('stock');
      throw new \RuntimeException('No hay suficiente stock disponible...');
  }
  ```
- **Suggestion:** Extraer a un método privado `handleInsufficientStock(Model $model, float $quantity): void` para eliminar duplicación.

#### 🟢 INFO-1: Uso de DB::table() directo en lugar de Query Builder del modelo
- **File:** `ManagementInventoryService.php:86-88, 116-119, 152-155, 188-190`
- **Standard:** `clean-architecture.md §3` (Consistencia de capas)
- **Issue:** Se usa `DB::table($model->getTable())` en lugar de aprovechar métodos del modelo Eloquent o un repositorio.
- **Suggestion:** Considerar agregar un método en el modelo `FinishedProductInventory` como `atomicDecrementStock(float $quantity): bool` para encapsular la lógica de actualización atómica y mantener consistencia con el patrón Eloquent.

#### 🟢 INFO-2: Falta validación de existencia del modelo antes de actualizar
- **File:** `ManagementInventoryService.php:86-88`
- **Standard:** `error-handling.md §1` (Fail Fast)
- **Issue:** `registerEntry()` y `registerReturn()` ejecutan `increment()` sin verificar que el modelo exista. Si `$model->id` no existe, la query simplemente no afecta filas pero no lanza error.
- **Suggestion:** Agregar verificación de filas afectadas también en incrementos, o validar existencia del modelo al inicio del método.

---

## Módulo 2: DetailsRelationManager

**Archivo:** `app/Filament/Resources/AssignedProductResource/RelationManagers/DetailsRelationManager.php`

### Hallazgos

#### 🔴 BLOCK-1: DeleteBulkAction sin transacción — inconsistencia de datos bajo fallo parcial
- **File:** `DetailsRelationManager.php:172-186`
- **Standard:** `concurrency.md §2` (Atomicity), `error-handling.md §6` (Resource Cleanup & Consistency)
- **Issue:** El `before()` hook del `DeleteBulkAction` itera sobre múltiples registros y llama a `managementInventoryDeleteDetail()` para cada uno. Si el proceso falla a mitad (ej: el 3er registro de 5 lanza excepción), los primeros 2 ya registraron movimientos ENTRADA pero sus `DetailAssignedProduct` no serán eliminados (Filament hace el delete después del `before()`).
  
  **Escenario problemático:**
  1. Usuario selecciona 5 productos para eliminar masivamente
  2. `before()` registra ENTRADA para producto 1, 2, 3
  3. Producto 3 lanza excepción (ej: stock negativo por otra operación concurrente)
  4. Filament aborta, no elimina ningún registro
  5. **Resultado:** Productos 1 y 2 tienen movimiento ENTRADA registrado pero siguen asignados → stock inflado artificialmente

- **Suggestion:** Envolver todo el `before()` en `DB::transaction()`:
  ```php
  ->before(function (\Illuminate\Database\Eloquent\Collection $records) {
      DB::transaction(function () use ($records) {
          foreach ($records as $record) {
              if ($record->sale_quantity > 0) {
                  throw ValidationException::withMessages([...]);
              }
              $this->managementInventoryDeleteDetail($record);
          }
      });
  })
  ```

#### 🔴 BLOCK-2: EditAction sin transacción — inconsistencia entre actualización y movimiento
- **File:** `DetailsRelationManager.php:150-168`
- **Standard:** `concurrency.md §2` (Atomicity), `error-handling.md §6` (Resource Cleanup & Consistency)
- **Issue:** El `using()` del `EditAction` actualiza el registro (`$record->update($data)`) y luego llama a `managementInventoryUpdateDetail()`. Si el movimiento falla (ej: stock insuficiente para SALIDA adicional), el registro ya fue actualizado pero el inventario no refleja el cambio.
  
  **Escenario problemático:**
  1. Usuario edita cantidad de 10 a 15 (diferencia = -5, requiere SALIDA)
  2. `$record->update(['quantity' => 15])` — éxito, quantity ahora es 15
  3. `managementInventoryUpdateDetail()` intenta SALIDA de 5 unidades
  4. Stock insuficiente → excepción lanzada
  5. **Resultado:** `DetailAssignedProduct.quantity = 15` pero stock no se decrementó → inconsistencia

- **Suggestion:** Envolver en `DB::transaction()`:
  ```php
  ->using(function (Model $record, array $data): Model {
      return DB::transaction(function () use ($record, $data) {
          $originalQuantity = $data['original_quantity'] ?? $record->quantity;
          // ... validaciones ...
          unset($data['original_quantity']);
          $record->update($data);
          $this->managementInventoryUpdateDetail($record, $originalQuantity);
          return $record;
      });
  })
  ```

#### 🟡 WARN-2: N+1 query potencial en DeleteBulkAction
- **File:** `DetailsRelationManager.php:174-184`
- **Standard:** `performance.md §2` (Database Access - N+1 problem)
- **Issue:** Dentro del loop, `managementInventoryDeleteDetail()` ejecuta una query para obtener `FinishedProductInventory` por cada registro. Con N productos seleccionados, son N queries adicionales.
- **Suggestion:** Precargar los inventarios antes del loop:
  ```php
  $productIds = $records->pluck('product_id');
  $inventories = FinishedProductInventory::where('branch_id', $branchId)
      ->whereIn('product_id', $productIds)
      ->get()
      ->keyBy('product_id');
  ```

#### 🟡 WARN-3: managementInventoryCreateDetail hace query redundante
- **File:** `DetailsRelationManager.php:197-211`
- **Standard:** `performance.md §2` (Database Access)
- **Issue:** En `CreateAction::using()` ya se obtiene `$inventory` con `lockForUpdate()` (línea 71-74), pero luego `managementInventoryCreateDetail()` ejecuta otra query idéntica para obtener el mismo `FinishedProductInventory`.
- **Suggestion:** Pasar el `$inventory` ya obtenido como parámetro a `managementInventoryCreateDetail()`:
  ```php
  $this->managementInventoryCreateDetail($record, $inventory);
  ```

#### 🟡 WARN-4: Error gramatical en mensajes de validación
- **File:** `DetailsRelationManager.php:132, 135, 177`
- **Standard:** N/A (Calidad de código)
- **Issue:** Los mensajes dicen "ya se han vendidos" (correcto) pero en algunos lugares dice "ya se han vendidos" cuando debería ser "ya se han vendido" (sin la 's' extra en "vendidos" cuando el sujeto es plural).
  - Línea 132: `"ya se han vendidos {$record->sale_quantity} productos"` → debería ser `"ya se han vendido"`
  - Línea 177: mismo error
- **Suggestion:** Corregir a "ya se han vendido" (el participio no lleva 's' en español).

#### 🟢 INFO-3: Validación de stock en CreateAction podría usar el inventario con lock
- **File:** `DetailsRelationManager.php:99`
- **Standard:** `concurrency.md §3` (Locking Discipline)
- **Issue:** La validación `$data['quantity'] > $inventory->stock` usa el `$inventory` obtenido con `lockForUpdate()`, lo cual es correcto. Sin embargo, el `ManagementInventoryService::registerExit()` también verifica stock internamente con `WHERE stock >= quantity`. Esto crea una doble verificación que, aunque no es incorrecta, podría dar falsos negativos si el stock cambia entre ambas verificaciones.
- **Suggestion:** Documentar que la verificación en `CreateAction` es una validación temprana para UX (mostrar error antes de intentar el movimiento), y que la verificación definitiva está en el servicio.

---

## Resumen de Acciones Requeridas

| Prioridad | Acción | Archivo |
|-----------|--------|---------|
| 🔴 BLOCK | Agregar `DB::transaction()` en `DeleteBulkAction::before()` | `DetailsRelationManager.php:172-186` |
| 🔴 BLOCK | Agregar `DB::transaction()` en `EditAction::using()` | `DetailsRelationManager.php:150-168` |
| 🟡 WARN | Extraer método común para manejo de stock insuficiente | `ManagementInventoryService.php` |
| 🟡 WARN | Precargar inventarios en DeleteBulkAction | `DetailsRelationManager.php:172-186` |
| 🟡 WARN | Pasar `$inventory` a `managementInventoryCreateDetail()` | `DetailsRelationManager.php:118, 197-211` |
| 🟡 WARN | Corregir error gramatical en mensajes | `DetailsRelationManager.php:132, 135, 177` |

---

## Conclusión

El fix implementado resuelve correctamente el problema de condiciones de carrera en la creación de asignaciones mediante:
- ✅ Uso de `lockForUpdate()` en `CreateAction`
- ✅ Operaciones atómicas `increment/decrement` en `ManagementInventoryService`
- ✅ Transacción en `CreateAction::using()`

Sin embargo, se identificaron **2 blockers** relacionados con falta de transacciones en `EditAction` y `DeleteBulkAction`, que pueden causar inconsistencias de datos similares a las que se intentaron corregir. Se requiere aplicar el mismo patrón de atomicidad a estas operaciones antes de aprobar el fix.
