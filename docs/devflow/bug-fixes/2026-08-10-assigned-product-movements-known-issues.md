# Productos asignados y movimientos — hallazgos pendientes

**Fecha:** 2026-08-10
**Origen:** revisión de `AssignedProductMovementService` y `SaleService::createSaleDetails`
tras el reporte de "el sistema muestra que sobran menos productos de los que el
vendedor lleva en físico".

Los hallazgos 1, 2, 3, 4, 5 y 7 de esa revisión ya están corregidos
(ver `AssignedProductStockGuardTest` y `AssignedProductMovementApiGuardTest`).
Este documento registra los dos que quedaron **abiertos a propósito**.

---

## Pendiente A — `ClientVisitService::registerVisit` puede tumbar la venta completa

**Estado:** documentado, sin corregir. Hoy no se manifiesta porque un cliente no
puede estar asignado a dos empleados.

**Dónde:** `app/Services/ClientVisitService.php:23-42`, invocado desde
`app/Services/SaleService.php` (paso 7 de `createSale`).

**Qué pasa:** el `updateOrCreate` busca la visita por la tripleta
`(client_id, user_id, visited_date)`:

```php
ClientVisit::updateOrCreate(
    ['client_id' => $clientId, 'user_id' => $userId, 'visited_date' => $date->toDateString()],
    ['visited' => $visited]
);
```

…pero el índice único de la tabla es sólo `(client_id, visited_date)`. La clave de
búsqueda es más estricta que la restricción de la base: si dos usuarios distintos
registran una venta al mismo cliente el mismo día, el segundo no encuentra la fila
existente, intenta insertar y choca contra el índice único. La excepción se propaga
hasta `SaleService::createSale`, que hace rollback: **la venta completa se pierde
con un 500**, no sólo la visita.

**Por qué no se manifiesta hoy:** la operación asigna cada cliente a un único
empleado, así que en la práctica siempre es el mismo `user_id`.

**Cuándo se va a manifestar:**
- si un cliente pasa a ser atendido por dos vendedores (ruta compartida, cubrir
  vacaciones, un supervisor que factura por el titular);
- si un mismo vendedor cambia de usuario;
- ya se reproduce hoy en SQLite dentro de los tests, porque el `visited_date`
  guardado como datetime (`2026-08-10 00:00:00`) no empata con la búsqueda por
  `toDateString()` (`2026-08-10`). Por eso `AssignedProductStockGuardTest` usa un
  cliente distinto por venta en el caso de ventas consecutivas.

**Cómo se arreglaría:**
1. Alinear la clave del `updateOrCreate` con el índice único: buscar por
   `(client_id, visited_date)` y mover `user_id` al array de valores; o bien
2. ampliar el índice único a `(client_id, user_id, visited_date)` si el negocio sí
   quiere una visita por vendedor; y
3. en cualquier caso, **no dejar que un fallo al registrar la visita anule la venta**:
   el registro de visita es un efecto secundario, no parte de la transacción de venta.

---

## Pendiente B — Movimientos huérfanos al borrar una venta

**Estado:** documentado, sin corregir. Se aborda junto con el trabajo de borrado /
anulación de ventas.

**Dónde:** `database/migrations/..._add_sale_id_to_assigned_product_movements_table.php`

```php
$table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
```

**Qué pasa:** si se elimina una venta, sus regalías y cambios asociados **no se
borran**: quedan con `sale_id = NULL` y los acumuladores `royalties_quantity` /
`changes_quantity` del `DetailAssignedProduct` nunca se revierten. El sobrante del
vendedor queda permanentemente por debajo del físico — exactamente el síntoma que
originó esta revisión, pero por otra vía.

Lo mismo aplica a `sale_quantity`: `SaleService` la incrementa al vender y no existe
hoy ningún camino que la devuelva.

**Qué falta cuando se implemente borrado/anulación de ventas:**

1. **Revertir los movimientos**, no sólo desligarlos. `AssignedProductMovementService::deleteMovement`
   ya revierte el acumulador correctamente (con `lockForUpdate` y piso en 0): hay que
   invocarlo para cada movimiento con ese `sale_id` antes de borrar la venta, en la
   misma transacción.
2. **Revertir `sale_quantity`** de cada `DetailAssignedProduct` afectado por los
   `SaleDetail` de la venta, también con `lockForUpdate`.
3. **Decidir entre borrado y anulación.** El enum `SaleStatusEnum` ya tiene el caso
   `CANCELLED` pero no hay flujo que lo use. Anular preservando el histórico es
   preferible a borrar; en ese caso la reversión debe dispararse al pasar a
   `CANCELLED`, no en el `delete`.
4. **Bloquear la reversión si el día ya tiene cuadre** (`DailySalesReconciliation`):
   revertir acumuladores de un día cerrado descuadra el cierre. Ver la validación
   equivalente en `SaleController::validateExistingReconciliation`.
5. **Cambiar `nullOnDelete()`**. Una vez que exista reversión explícita, el
   `nullOnDelete` es una trampa: deja el dato inconsistente en silencio. O se pasa a
   `restrictOnDelete` (forzando a anular antes de borrar) o a `cascadeOnDelete`
   acompañado de la reversión del acumulador.
6. **Tests de regresión**: venta con regalía → anular → `royalties_quantity` y
   `sale_quantity` vuelven a su valor previo y `stock` vuelve al original.
