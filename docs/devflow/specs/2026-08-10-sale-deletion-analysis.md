# Análisis: borrado / anulación de ventas

**Fecha:** 2026-08-10
**Estado:** análisis cerrado — reglas de negocio definidas (§0), sin decisiones pendientes (§11). Listo para implementar (§12).
**Relacionado:** `docs/devflow/bug-fixes/2026-08-10-assigned-product-movements-known-issues.md` (Pendiente B)

---

## 0. Reglas de negocio definidas

Decisiones tomadas el 2026-08-10. Todo el resto del documento está condicionado a ellas.

| # | Regla |
|---|---|
| R1 | **Ventana temporal:** sólo se puede anular una venta **el mismo día**. Otro día, no se permite. |
| R2 | **Cuadre:** si el día ya tiene cuadre, no se permite anular. |
| R3 | **Contado:** se puede anular aunque la venta esté `paid`. |
| R4 | **Crédito:** sólo si la cuenta por cobrar **no tiene ningún pago registrado**. En ese caso se anula la venta y la CxC se marca como **`CANCELLED`** (no se borra). |
| R5 | **Con pagos registrados:** prohibido anular, sin excepción. |
| R6 | **Abono inicial:** una venta a crédito con `cash_amount > 0` **sí se puede anular** (ese monto se descuenta directo, no genera fila en `payments`). La UI debe avisar cuánto hay que devolverle al cliente. |
| R7 | **"Mismo día" = `sale_date` y `created_at`**, ambos hoy. Además, **se bloquea la edición de la fecha de venta en la web**: el campo pasa a sólo lectura y la fecha la fija el servidor. |
| R8 | **Reversión de inventario (web):** asiento compensatorio de tipo **`DEVOLUCION`**. |

### Lo que estas reglas eliminan del problema

R1 + R2 juntas son una simplificación grande, y conviene dejarla explícita porque
condiciona toda la arquitectura:

- **No hay que recalcular ni reabrir cuadres.** El cuadre siempre se calcula *después* de
  cualquier anulación posible, así que basta con excluir las ventas anuladas de las
  consultas (§7.1). Los snapshots de `daily_sales_reconciliations` nunca quedan mintiendo.
- **No hay que revertir efectivo ya contado.** Ninguna anulación puede tocar un día cerrado.
- **No hay que tocar `returned_quantity`.** Las devoluciones del sobrante sólo se registran
  desde el flujo de cuadre, y ese flujo crea una fila `pending` en
  `daily_sales_reconciliations` en cuanto se inicializa
  (`CreateReconciliation::createPendingReconciliation`). Como R2 bloquea ante *cualquier*
  cuadre —incluido el pendiente—, el invariante que preocupaba en §4.4 no puede romperse.
- **No hay reapertura de cuadre que diseñar** (§11.5 del análisis original queda cerrada).

### Estados anulables (resuelve el problema de `canBeCancelled()`)

`SaleStatusEnum::canBeCancelled()` hoy admite sólo `DRAFT` y `CONFIRMED`, y bloquearía el
89% de las ventas reales. Con R3 y R4 queda así:

| Estado | ¿Anulable? |
|---|---|
| `DRAFT` | sí (no se usa hoy) |
| `CONFIRMED` | sí |
| `PARTIALLY_PAID` | sí, sujeto a R4/R5 |
| `PAID` | **sí** (era el caso bloqueado) |
| `CANCELLED` | no-op idempotente, responde éxito |
| facturada (`invoice_number` no nulo) | no, nunca |

Hay que reescribir `canBeCancelled()` en consecuencia, o —mejor— **no usarlo** para esta
decisión y dejar toda la política en el servicio de anulación, donde se puede combinar con
la fecha, el cuadre y los pagos. El método del enum sólo conoce el estado, y la política
real depende de cuatro cosas más.

---

## 1. Punto de partida: el borrado ya existe y está roto

Antes de diseñar nada, esto es lo que hay hoy en producción:

| Dónde | Qué hace |
|---|---|
| `app/Filament/Resources/SaleResource.php:124` | `DeleteBulkAction` en la tabla de ventas |
| `app/Filament/Resources/SaleResource/Pages/EditSale.php:17` | `DeleteAction` en el encabezado de edición |
| API (`routes/api.php`) | **no existe** endpoint de borrado |

Ambas acciones de Filament hacen un `delete()` de Eloquent sin ninguna reversión.
Comprobado contra la BD real (dentro de una transacción revertida):

```
Probando borrar venta #458 (3 detalles)
FALLÓ: SQLSTATE[23000]: 1451 Cannot delete or update a parent row:
       a foreign key constraint fails (`jaco`.`sale_details`, ...)
```

**No corrompe datos, pero porque la base lo impide, no porque el código lo prevea.**
Las FKs reales hacia `sales` son:

| Tabla | Columna | ON DELETE | Consecuencia |
|---|---|---|---|
| `sale_details` | `sale_id` | **NO ACTION** (=RESTRICT) | el borrado falla siempre que la venta tenga líneas |
| `sale_tax_totals` | `sale_id` | CASCADE | se borra en silencio (hoy la tabla está vacía: 0 filas) |
| `account_receivables` | `sales_id` | **SET NULL** | la cuenta por cobrar sobrevive huérfana, con sus pagos |
| `assigned_product_movements` | `sale_id` | **SET NULL** | la regalía sobrevive y el acumulador no se revierte |

Es decir: si alguien "arregla" el `RESTRICT` de `sale_details` sin más, el borrado
empieza a funcionar y **destruye silenciosamente** cuentas por cobrar y regalías.
Ese es el riesgo principal de este trabajo.

Dos datos más que condicionan el diseño:

- **`invoice_number` nunca se asigna** en ningún punto del código. Hoy ninguna venta
  está facturada, así que no hay problema fiscal de numeración — pero el campo existe
  y el diseño debe contemplarlo.
- **`sale_tax_totals` nunca se escribe** (0 filas). Su CASCADE es inofensivo hoy.

---

## 2. Decisión de fondo: anulación, no borrado físico

**Recomendación: implementar anulación (`status = CANCELLED` + reversión de efectos)
y prohibir el borrado físico**, incluso desde Filament.

Razones, de más a menos fuerte:

1. **La idempotencia depende de que la fila exista.** `sales.client_request_uuid` tiene
   índice único y `SaleController::createSale` responde "ya registrada" cuando lo
   encuentra. Si se borra la fila, el uuid queda libre: un reintento tardío del móvil
   (o un reenvío de la cola offline) **vuelve a crear la venta que se acababa de borrar**,
   y con ella vuelve a descontar `sale_quantity`. La anulación conserva la fila y el
   reintento sigue respondiendo "ya registrada".
2. **La anulación es idempotente por construcción.** Anular dos veces es un no-op
   (`if ($sale->isCancelled()) return;`). Borrar dos veces, con reversión de por medio,
   revierte dos veces si no se acierta con el bloqueo.
3. **Auditoría.** Es una operación de dinero: hay que poder responder quién anuló qué y
   cuándo. `sales` ya tiene `updated_by`; falta `cancelled_at` / `cancelled_by` / `cancellation_reason`.
4. **El patrón ya existe en el código.** `ProductReturnService::reverseInventoryMovement`
   (`app/Services/ProductReturnService.php:86`) revierte **creando un movimiento
   compensatorio**, no borrando el original. El histórico de `management_inventory` es
   un libro de asientos: se compensa, no se edita.
5. `SaleStatusEnum::CANCELLED` y `Sale::canBeCancelled()` ya existen, sin usarse.

**Consecuencia de diseño:** todo lo que sigue describe "anular". Si el negocio insiste
en borrado físico para algún caso (p. ej. una venta creada por error hace 5 minutos),
que sea un caso aparte, restringido y posterior — y siempre ejecutando primero la misma
reversión.

Ver §0 para los estados anulables ya definidos.

---

## 3. Inventario completo de efectos de una venta

Todo lo que una venta toca al crearse, y qué hay que hacer al anularla:

| # | Efecto | App | Web | Reversión |
|---|---|---|---|---|
| 1 | `sale_details` (líneas) | ✅ | ✅ | conservar (el histórico es la evidencia) |
| 2 | `detail_assigned_products.sale_quantity` | ✅ | ❌ | restar la cantidad vendida |
| 3 | `detail_assigned_products.royalties_quantity` / `changes_quantity` | ✅ | ❌ | revertir vía `AssignedProductMovementService::deleteMovement` |
| 4 | `assigned_product_movements` (filas con `sale_id`) | ✅ | ❌ | eliminar junto con su acumulador |
| 5 | `finished_product_inventories.stock` | ❌ | ✅ | movimiento compensatorio de entrada |
| 6 | `management_inventory` (asiento `salida`) | ❌ | ✅ | **no borrar**: crear el asiento inverso |
| 7 | `account_receivables` (+ `remaining_balance`, `status`) | ✅ | ✅ | R4: `status = CANCELLED` + `cancelled_at` (sólo si no tiene pagos) |
| 8 | `payments` sobre esa CxC | ✅ | ✅ | R5: si existe alguno, la anulación se rechaza |
| 9 | `client_visits` (visita del día) | ✅ | ❌ | no revertir (§5.5) |
| 10 | `daily_sales_reconciliations` (snapshot del cuadre) | ✅ | ✅ | R2: bloquear — nunca se recalcula (§7.1) |
| 11 | `sale_tax_totals` | ❌ | ❌ | no aplica (nunca se escribe) |

Nota sobre el punto 9: la web (`app/Livewire/Sales/CreateSale.php:443`) **no** registra
visita de cliente; la app sí (`SaleService::createSale`, paso 7). Es una asimetría
existente entre los dos flujos, no introducida por este trabajo.

---

## 4. Caso A — venta creada desde la app

Tu descripción es correcta: **no toca inventario**, porque el inventario ya se descontó
al asignarle el producto al vendedor. Lo que hay que revertir es el detalle del producto
asignado. Pero hay cinco trampas concretas.

### 4.1 La asignación se debe resolver por la venta, no por el usuario logueado

> R1 obliga a que la anulación sea del mismo día, así que la parte de la fecha parece
> irrelevante. **No lo es:** quien anula puede ser un admin o un cajero, que no tienen
> `employee` asociado. El problema sigue en pie por el actor, no por la fecha.

La creación busca la asignación así (`app/Services/SaleService.php`, rama `origin === 'api'`):

```php
AssignedProduct::where('employee_id', Auth::user()->employee->id)
    ->todayAssignments()   // whereDate('date', today())
    ->first();
```

Usa **el empleado del usuario autenticado** y **la fecha de hoy**. Para revertir eso no
sirve: quien anula puede ser un admin (que no tiene `employee`), o la anulación puede
ocurrir al día siguiente. La reversión debe resolver:

```php
AssignedProduct::where('employee_id', $sale->employee_id)
    ->whereDate('date', $sale->sale_date)
    ->first();
```

Si se copia el patrón de la creación, la anulación de un admin fallaría con
`Attempt to read property "id" on null`, o peor, revertiría contra la asignación
equivocada. Ojo también con la duplicidad: `createMovementFromSaleProduct` ya usa
`$sale->employee_id + whereDate($sale->sale_date)` mientras la rama de venta usa
`Auth + today()`. **Los dos caminos de la misma función miran asignaciones distintas.**

### 4.2 El "silent skip" de la creación se hereda en la reversión

La creación hace `if ($detail) { ... }` — si no encuentra el detalle, **no descuenta y no
avisa**. Al revertir hay que decidir explícitamente qué pasa cuando el detalle ya no
existe (asignación borrada, producto quitado de la asignación):

- **Recomendado: fallar la anulación completa** con un mensaje claro. Un descuadre
  silencioso es exactamente el bug que acabamos de corregir.
- Alternativa: revertir lo que se pueda y devolver la lista de líneas no revertidas para
  que un humano decida. Nunca continuar en silencio.

### 4.3 Revertir por `quantity`, no por `base_quantity`

La creación incrementa `sale_quantity` con `$productData['quantity']`; el inventario web,
en cambio, usa `base_quantity`. En la app hoy ambos valen lo mismo
(`prepareSaleDetailsData` hace `'quantity' => (int)…, 'base_quantity' => (int)…`), pero
si algún día se activa `conversion_factor` dejan de coincidir. La reversión debe usar
**la misma magnitud que usó la creación** (`sale_details.quantity`), y conviene dejarlo
comentado en el código para que no se "corrija" a `base_quantity` por error.

### 4.4 El invariante frente a las devoluciones — cubierto por R2

`CreateReconciliation::updateReturnedQuantity` escribe `returned_quantity` con tope
`quantity - sale_quantity`. Si después bajara `sale_quantity`, el sobrante calculado
subiría por encima del físico.

**R2 lo previene por completo:** ese flujo llama antes a `createPendingReconciliation()`,
que inserta la fila de cuadre en estado `pending`. Como el bloqueo de R2 consulta
`exists()` sin filtrar por estado —igual que el guardia de creación ya existente en
`SaleController::validateExistingReconciliation`—, en cuanto hay una devolución registrada
ya no se puede anular.

→ Requisito derivado: **el bloqueo por cuadre debe mirar cualquier estado**, no sólo
`completed`. Si alguien "optimiza" ese chequeo filtrando por cuadres completados, reabre
este agujero. Vale la pena un test explícito con cuadre `pending`.

→ Aun así, conviene dejar una **aserción defensiva** del invariante
`sale_quantity + returned_quantity + changes + royalties ≤ quantity` al final de la
reversión: si alguna vez falla, indica que R2 se saltó por otra vía.

### 4.5 Bloqueo y orden

- `lockForUpdate()` sobre cada `detail_assigned_products` afectado, igual que en la venta
  y en los movimientos (ya corregido en el PR #128).
- Revertir **primero los movimientos** (regalías/cambios) y **después `sale_quantity`**, o
  al revés — pero de forma fija, no dependiente del orden de las líneas. Mismo criterio que
  se aplicó en `createSaleDetails`.
- Piso en 0 con detección: si al restar quedaría negativo, hay drift previo. Recortar a 0
  **y registrar un warning** en vez de tragárselo.
- Los movimientos ya tienen la reversión resuelta:
  `AssignedProductMovementService::deleteMovement` revierte el acumulador con bloqueo y
  piso en 0. Basta con iterar `$sale->assignedProductMovements` — la relación ya existe en
  `Sale` (`app/Models/Sale.php`).

---

## 5. Caso B — venta creada desde la web

También correcto tu planteamiento: aquí **sí** hay que tocar inventario y **no** hay
asignación que revertir, porque el cajero/admin no tiene productos asignados. Pero esta
rama tiene un problema de datos que hoy hace la reversión **imposible de hacer bien**.

### 5.1 No se puede saber de qué inventario salió el producto

La creación web (`app/Livewire/Sales/CreateSale.php:516`) resuelve el inventario con
`FinishedProductInventory::find($item['inventory_id'])`, donde `inventory_id` vive sólo en
el estado del componente Livewire. **`sale_details` no tiene columna `inventory_id`**, y —
esto es lo grave — **`sales` no tiene columna `branch_id`**:

```
DESCRIBE sales →  id, invoice_number, invoice_series_id, client_id, employee_id,
                  sale_date, due_date, status, payment_term, payment_method,
                  cash_amount, subtotal, discount_*, total_amount, notes,
                  client_request_uuid, created_by, updated_by, confirmed_at, timestamps
```

`SaleService::createSale` pasa `'branch_id' => $saleData['branch_id']` a `Sale::create()`,
pero `branch_id` **no está en `$fillable` ni existe como columna**: Eloquent lo descarta en
silencio. Lo mismo ocurre con `payment_reference` (se envía desde la API y desde la web,
no está en `$fillable` ni en la tabla) y con `deposit_id` (está en `$fillable` pero no en
la tabla).

→ Sin sucursal en la venta, restaurar stock exige adivinar el inventario por
`(product_id, branch)` usando `employee->branch_id` **actual**, que puede haber cambiado.
Es una fuente de errores garantizada.

### 5.2 La fuente de verdad correcta son los asientos de `management_inventory`

En vez de re-resolver el inventario, la reversión debe leer los asientos que la propia
venta generó y compensarlos uno a uno. Es el patrón de `ProductReturnService` y es exacto:
compensa contra el mismo `model_id`, con la misma cantidad, aunque la sucursal del empleado
haya cambiado después.

**Pero hay una colisión que hay que resolver antes:** `management_inventory` guarda
`reference_id` sin un `reference_type`. `SaleService` escribe `reference_id = $sale->id` y
`ProductReturnService` escribe `reference_id = $productReturn->id`, ambos sobre
`model_type = FinishedProductInventory`. La venta #5 y la devolución #5 son
indistinguibles. Hay 1.396 asientos de tipo `salida` en la BD.

→ **Prerrequisito bloqueante:** añadir `reference_type` (morph) a `management_inventory`,
o una columna `sale_id` dedicada. Sin eso, cualquier reversión "por evidencia" puede
compensar el asiento equivocado.

### 5.3 Qué tipo de movimiento usar para compensar

`ManagementInventoryService` tiene dos tipos que suben stock: `ENTRADA` y `DEVOLUCION`
(`registerEntry` / `registerReturn`, ambos `increment('stock')`).

**Decidido (R8): `DEVOLUCION`.** Es el correcto semánticamente para una venta anulada y
deja el reporte de inventario legible frente a las entradas por producción o compra.

La descripción del asiento debe permitir rastrear el par: p. ej.
`"Anulación de venta #INV-458 - {producto}"`, siguiendo el formato que ya usa
`ProductReturnService`: `"Reversión de devolución #{id} - ..."`.

### 5.4 Anular no puede fallar por falta de stock

`registerReturn` sólo incrementa, así que no falla. Pero **no hay que caer en la tentación
de usar `registerExit` inverso ni de validar stock**: la anulación devuelve producto, nunca
lo retira. Si en el futuro se implementa "editar venta", ahí sí habrá que validar.

### 5.5 La visita del cliente no se revierte

La web ni siquiera la registra. En la app, la visita es un hecho ocurrido (el vendedor
estuvo allí) independiente de que la venta se anule. Recomendación: **no tocar
`client_visits`**, y dejarlo escrito para que no se reabra la discusión.

---

## 6. Cómo distinguir el origen de la venta (problema real)

No hay forma fiable hoy. `sales` no tiene columna de canal, y el `'origin' => 'api'` que
usa `SaleService` vive sólo en el array de entrada, nunca se persiste.

La heurística obvia — "tiene `client_request_uuid` ⇒ vino de la app" — **no funciona**:

```
ventas con client_request_uuid: 0
ventas sin client_request_uuid: 426
```

La columna se añadió el 2026-06-13 y no hay datos históricos. Además `SaleRequest` la
declara `nullable`, así que una app vieja puede seguir sin enviarla.

**Recomendación doble:**

1. **Añadir `sales.channel`** (`enum('app','web')`, no nulo, con default por migración) y
   backfill del histórico por la evidencia disponible: existe una `AssignedProduct` para
   `(employee_id, sale_date)` y hay `detail_assigned_products` de esos productos ⇒ `app`;
   hay asientos de `management_inventory` con ese `sale_id` ⇒ `web`. Revisar a mano lo
   ambiguo (deberían ser pocas).
2. **Pero no basar la reversión en el canal, sino en la evidencia.** El servicio de
   anulación debería preguntarse, para cada venta:
   - ¿hay `detail_assigned_products` para `(sale.employee_id, sale.sale_date, product_id)`?
     → revertir `sale_quantity`.
   - ¿hay asientos de `management_inventory` con esta venta como referencia?
     → compensarlos.

   Así una venta mal clasificada, o un caso mixto futuro, se revierte igual de bien.
   El `channel` queda para reportería, permisos y mensajes de UI.

---

## 7. Precondiciones que deben bloquear la anulación

Cada una debe ser un chequeo explícito con mensaje propio, no un `try/catch` genérico.
El orden importa: primero el no-op idempotente, después las baratas, al final las que
consultan otras tablas.

```
0. venta ya CANCELLED            → éxito (no-op, §8)
1. venta facturada                → 409 "Una venta facturada no se anula, se emite nota de crédito"
2. R1: no es del día              → 422 "Sólo se pueden anular ventas del mismo día"
3. R2: existe cuadre (cualquier estado) → 422 "El día ya tiene cuadre"
4. R5: la CxC tiene pagos         → 422 "La venta tiene pagos registrados"
5. autorización por rol/canal     → 403
```

### 7.0 R1 / R7 — sólo el mismo día

**El chequeo exige las dos fechas: `sale_date` = hoy **y** `created_at` = hoy.** La primera
preserva la coherencia con el cuadre (que agrupa por `sale_date`); la segunda cierra el
hueco de la retrofecha.

**Además se bloquea la edición de la fecha de venta en la web.** Hoy es un campo libre:

- `resources/views/livewire/sales/create-sale.blade.php:102` →
  `<input type="date" wire:model="sale_date" …>`
- `app/Livewire/Sales/CreateSale.php:34,78,479` → la propiedad se inicializa con
  `now()` pero el usuario puede sobrescribirla, y se guarda tal cual.

Cambio requerido: el input pasa a **sólo lectura** (mostrar la fecha, no permitir editarla)
y el `save()` debe fijar `'sale_date' => now()` en el servidor, **sin confiar en la
propiedad del componente** — un campo `readonly` en el HTML no impide que un cliente
Livewire manipulado envíe otra fecha. Las dos cosas, no una.

Con esto, `sale_date` y `created_at` coinciden siempre para ventas nuevas y la doble
verificación se vuelve redundante en la práctica — pero se deja igual, porque protege al
histórico ya existente (426 ventas creadas bajo las reglas viejas).

**Zona horaria:** `config/app.php` usa `America/Tegucigalpa`. "Hoy" es el día local y el
corte es la medianoche local. Una venta de las 23:55 tiene cinco minutos de ventana. Es
consecuencia directa de R1, no un defecto — pero el mensaje de error debe decir "sólo el
mismo día" para que el vendedor no lo lea como un bug.

### 7.1 R2 — cuadre del día

`daily_sales_reconciliations` guarda **snapshots calculados**, no vistas en vivo:
`total_cash_sales`, `cash_sales`, `total_credit_sales`, `deposit_sales`, `total_sales`,
`total_cash_expected`, `cash_difference`, … Si se anula una venta de un día ya cuadrado,
el snapshot queda mintiendo y el `cash_difference` calculado ese día deja de tener sentido.

Ya existe el guardia simétrico para creación:
`SaleController::validateExistingReconciliation` (`app/Http/Controllers/SaleController.php:41`)
impide crear ventas si ya hay cuadre del día. **La anulación necesita el mismo bloqueo**
(hay 6 cuadres en la BD).

**R2 resuelve esto bloqueando**, y con eso los snapshots nunca quedan desactualizados: no
hay que recalcular ni reabrir nada. El chequeo debe consultar `exists()` **sin filtrar por
estado** (ver §4.4: un cuadre `pending` también bloquea).

**Consecuencia obligatoria:** `CreateReconciliation` carga las ventas del día **sin filtrar
por estado** (`app/Livewire/Reconciliations/CreateReconciliation.php:208`). Como sí se
pueden anular ventas del día antes del cuadre, **hay que añadir el filtro
`status != cancelled`** o la venta anulada seguirá sumando. Rastrear el cambio en:

| Dónde | Qué suma |
|---|---|
| `CreateReconciliation:208` | ventas del día del empleado (alimenta todos los totales del cuadre) |
| `SaleController::getSales` | listado del día en la app |
| `SalesRankingWidget` | ranking de ventas |
| Dashboards / reportes que agreguen `sales` | revisar uno por uno |

Conviene resolverlo con un **scope reutilizable** (`Sale::scopeNotCancelled`) en vez de
repetir el `where` en cada consulta, y añadirlo a la lista de revisión de cualquier reporte
nuevo.

### 7.2 R4/R5 — cuenta por cobrar y pagos

Regla: **la venta a crédito sólo se anula si su CxC no tiene ningún pago registrado**. Con
cualquier pago aplicado, prohibido (R5).

Hoy hay 21 CxC ligadas a ventas y 8 pagos registrados.

**Chequeo:** `$sale->accountReceivable?->payments()->exists()` → si existe alguno, 422.

**Acción (R4): marcar la CxC como cancelada, no borrarla.** No hace falta ninguna
migración: `account_receivables.status` ya es
`enum('pending','paid','overdue','cancelled')` y la columna `cancelled_at` ya existe (ambas
verificadas contra la BD).

```php
$sale->accountReceivable?->update([
    'status'       => AccountReceivableStatusEnum::CANCELLED,
    'cancelled_at' => now(),
]);
```

Consecuencia a revisar: **toda consulta de CxC debe excluir las canceladas.** El estado
existía en el enum pero nunca se usaba, así que es probable que ninguna consulta filtre por
él. Hay que rastrearlo en `AccountReceivableController::getAccountReceivable`,
`getPaymentsToDay`, `AccountReceivableResource` (Filament) y en la validación
`AccountReceivableService::validateSale`, que cuenta las cuentas activas del cliente. Mismo
criterio que el `scopeNotCancelled` de ventas: un scope reutilizable, no `where` sueltos.

Ojo con el orden dentro de la transacción: cancelar la CxC **antes** de tocar la venta, para
que el `SET NULL` de `account_receivables.sales_id` no llegue a intervenir en ningún caso.

### 7.2.1 R6 — abono inicial

Una venta a crédito puede llevar `cash_amount > 0` (el cliente abona algo al momento). Ese
abono **no genera fila en `payments`**: `AccountReceivableService::create` lo descuenta
antes, creando la CxC ya con `total_amount = total − abono`.

**Decisión (R6): se permite anular.** R1+R2 garantizan que el cuadre aún no se hizo, así que
el efectivo se autocorrige solo al excluir la venta anulada de los totales.

Requisito de UI, no opcional: antes de confirmar, mostrar **"Debe devolver L X al cliente"**
con `X = sale.cash_amount`. Aplica en la app y en Filament. Sin ese aviso, el vendedor
anula y se queda con dinero que ya no corresponde a ninguna venta.

Importante para quien implemente: el chequeo de R5 es **`payments()->exists()`, y `cash_amount`
NO se toma en cuenta**. Queda escrito aquí para que no se "corrija" a futuro pensando que es
un descuido.

### 7.3 Venta facturada

`isInvoiced()` ya existe. Hoy siempre es falso (`invoice_number` no se asigna en ninguna
parte del código), pero el guardia debe estar desde el día 1: una factura emitida no se
anula, se emite nota de crédito.

### 7.4 Rol y pertenencia

Ver §9. R1 ya acota la ventana temporal para todos los roles por igual.

---

## 8. Concurrencia, idempotencia y transaccionalidad

- **Una sola transacción** para toda la anulación: estado + acumuladores + movimientos +
  asientos de inventario + CxC. `ManagementInventoryService` abre su propia
  `DB::transaction` por movimiento; anidadas en Laravel funcionan como savepoints, así que
  la externa sigue mandando. Verificarlo con un test que fuerce fallo a mitad.
- **`lockForUpdate`** sobre la venta y sobre cada `detail_assigned_products`, en orden
  estable por `id` para no invertir el orden de bloqueo respecto a la creación (riesgo de
  deadlock si un vendedor anula mientras otro vende el mismo producto).
- **Idempotencia:** `if ($sale->isCancelled()) return;` **dentro** de la transacción y
  después del `lockForUpdate`, no antes. Dos DELETE simultáneos del móvil deben revertir
  una sola vez.
- **Reintento del móvil:** el endpoint de anulación debe responder 200 (no 404/409) si la
  venta ya está anulada, para que la cola offline no se atasque.

---

## 9. Autorización

R1 (mismo día) y R2 (sin cuadre) aplican **a todos los roles por igual**, incluido el admin.
Encima de eso:

| Actor | Puede anular | Restricciones adicionales |
|---|---|---|
| Vendedor (app) | sus propias ventas | mismo `employee_id` que la venta |
| Cajero (web) | las que él creó | `CashierSaleScope` ya filtra por `created_by`; respetarlo también en la anulación |
| Admin / superadmin | todas las del día | motivo obligatorio, queda auditado |

Nota: `ListSales::getTableQuery` filtra por `employee_id` para el cajero, mientras
`CashierSaleScope` filtra por `created_by`. **Son dos criterios distintos para el mismo
rol**; conviene unificarlos antes de colgar permisos de anulación encima.

---

## 10. Deuda de esquema previa (prerrequisitos)

Ordenados por bloqueo:

1. **`management_inventory.reference_type`** (o `sale_id` dedicado) — sin esto la reversión
   web no es fiable. **Bloqueante.**
2. **`sales.branch_id`** — hoy se envía y se descarta en silencio; necesario para reportería
   y como respaldo de la resolución de inventario. Backfill desde `employee.branch_id`.
3. **`sales.cancelled_at`, `cancelled_by`, `cancellation_reason`** — auditoría.
4. **`sales.channel`** (`app`/`web`) — §6.
5. **`sales.payment_reference`** — se envía desde la API y desde la web y se pierde; sin él
   no se puede rastrear una transferencia de una venta anulada. (`deposit_id` está en
   `$fillable` pero tampoco existe como columna: decidir si se agrega o se quita del modelo.)
6. Revisar el `SET NULL` de `account_receivables.sales_id` y de
   `assigned_product_movements.sale_id`: una vez que exista reversión explícita, el
   `SET NULL` es una trampa que deja datos inconsistentes en silencio. Cambiar a
   `restrictOnDelete` refuerza "no se borra, se anula".

---

## 11. Decisiones — todas cerradas

No queda ninguna decisión de negocio pendiente. El análisis está listo para pasar a
implementación.

| Pregunta | Resolución |
|---|---|
| ¿Qué estados se pueden anular? | §0 — incluye `PAID` de contado |
| ¿Qué pasa con los pagos ya recibidos? | R5 — prohibido anular |
| ¿Anular días anteriores / reabrir cuadres? | R1/R2 — no. **No se diseña reapertura de cuadre** |
| ¿Venta a crédito con abono inicial? | R6 — se permite, con aviso de devolución en la UI |
| ¿"Mismo día" por qué campo? | R7 — `sale_date` **y** `created_at`, y se bloquea editar la fecha en la web |
| ¿`DEVOLUCION` o `ENTRADA`? | R8 — `DEVOLUCION` |
| ¿Se borra o se cancela la CxC? | R4 — `status = CANCELLED` + `cancelled_at` |
| ¿Anulación parcial (quitar una línea)? | Fuera de alcance — es otro ciclo (recalcular totales, CxC e impuestos) |
| ¿Sigue existiendo el borrado físico? | No — se retiran `DeleteAction` y `DeleteBulkAction` de Filament y se sustituyen por "Anular" |

---

## 12. Plan de implementación sugerido

| Fase | Contenido | Depende de | Estado |
|---|---|---|---|
| 0 | Retirar `DeleteAction`/`DeleteBulkAction` de Filament (hoy sólo producen un error SQL) | — | ✅ hecho (rama `feature/sale-cancellation`) |
| 1 | **R7 web:** fecha de venta a sólo lectura en el blade + `sale_date = now()` forzado en el servidor (§7.0) | — | ✅ hecho |
| 2 | `Sale::scopeNotCancelled` + filtrarlo en cuadre, `getSales`, ranking y reportes (§7.1). Scope equivalente para CxC canceladas (§7.2) | — | ✅ hecho |
| 3 | Migraciones §10: `management_inventory.reference_type` (**bloqueante**), `sales.branch_id`, `cancelled_at`/`cancelled_by`/`cancellation_reason`, `channel`, `payment_reference` + backfill | — | ✅ hecho |
| 4 | `SaleCancellationService`: precondiciones (§7), reversión app (§4) y web (§5), CxC a `CANCELLED` (§7.2), transacción y bloqueos (§8) | fase 3 | pendiente |
| 5 | Acción "Anular" en Filament con motivo obligatorio y aviso de devolución del abono (R6) | fase 4 | pendiente |
| 6 | `DELETE /api/sales/{id}` con pertenencia (§9), idempotencia (§8) y aviso de devolución (R6) | fase 4 | pendiente |
| 7 | `SET NULL` → `restrictOnDelete` en `account_receivables.sales_id` y `assigned_product_movements.sale_id` (§10.6) | fase 4 | pendiente |

Las fases 0, 1 y 2 eran independientes entre sí y de todo lo demás, y ya están hechas:

- La 0 quitó dos botones que sólo producían un error SQL
  (`app/Filament/Resources/SaleResource.php`, `.../Pages/EditSale.php`).
- La 1 cierra el hueco de la retrofecha: el input de fecha quedó `readonly`/`disabled`
  en `create-sale.blade.php` y `CreateSale::save()` ya no confía en la propiedad del
  componente, persiste `now()` en el servidor sin importar lo que llegue del cliente.
- La 2 añadió `Sale::scopeNotCancelled` y `AccountReceivable::scopeNotCancelled`, y se
  aplicaron en: `SaleController::getSales`, `CreateReconciliation::loadEmployeeDataOnly`,
  `SalesRankingWidget`, `StatsOverview` (ventas del mes + empleado destacado),
  `AccountReceivableController::getAccountReceivable` y `getPaymentsToDay`.
  `AccountsReceivableWidget` no necesitó cambio: ya sumaba sólo `status = "pending"`.
  No cambia ningún número hoy (no hay ventas anuladas ni CxC canceladas todavía), pero
  deja el terreno listo para la fase 4.

Cubierto por `tests/Feature/SaleCancellationScaffoldingTest.php` (7 tests): los dos
scopes, su aplicación en el endpoint de ventas del día, en el cuadre y en el listado de
CxC del empleado, la fecha forzada en servidor pese a un valor manipulado del cliente, y
la ausencia de `DeleteAction` en la edición de venta.

La fase 3 también está hecha:

- **`management_inventory.reference_type`** (nullable, `after reference_id`) — backfill
  histórico por patrón de descripción (única señal disponible; no hay ninguna otra forma
  de reconstruir el origen retroactivamente): 621 filas → `AssignedProduct::class`, 30 →
  `ProductReturn::class`, 1.390 sin clasificar (asientos legacy con `reference_id` ya
  NULL — no había nada que resolver). `ManagementInventoryService::processMovement` y
  los 4 métodos `register*` ahora aceptan `?string $referenceType` y lo persisten; se
  actualizaron los 4 call sites existentes (`SaleService`, `CreateSale` web,
  `ProductReturnService` ×2, `DetailsRelationManager` ×3) para pasar la clase
  correspondiente. `MovementsInventoryRelationManager` (ajuste manual) sigue sin
  referencia, como antes.
- **`sales.branch_id`, `payment_reference`** — ya se enviaban a `Sale::create()` pero
  Eloquent los descartaba en silencio por no existir. Backfill de `branch_id` desde
  `employees.branch_id` (426/426 resueltas). El backfill se implementó recorriendo por
  empleado en vez de `UPDATE...JOIN`: esa sintaxis no es portable a SQLite, que es el
  motor de la suite de tests.
- **`sales.cancelled_at`, `cancelled_by`, `cancellation_reason`** — columnas de
  auditoría, sin backfill (no hay ventas anuladas todavía). Relación `cancelledBy()`
  agregada al modelo.
- **`sales.channel`** (`SaleChannelEnum::APP|WEB`) — backfill por evidencia: existe
  `AssignedProduct` para `(employee_id, sale_date)` ⇒ `app`, si no ⇒ `web`. Resultado
  real: las 426 ventas históricas clasificaron como `app` (consistente con que no hay
  ningún asiento de inventario con `reference_type = Sale::class` en el histórico: nunca
  hubo una venta web con movimiento de inventario en esta base). El enum deja escrito en
  su docblock que la reversión de la fase 4 no debe decidir por este campo, sino por
  evidencia directa — igual criterio que el backfill.

Cubierto por `tests/Feature/ManagementInventoryReferenceTypeTest.php` (4 tests, incluido
uno que fuerza una colisión real de `reference_id` entre una venta y una devolución con
el mismo id y verifica que `reference_type` las distingue) y
`tests/Feature/SaleSchemaMetadataTest.php` (3 tests). Verificado en rojo contra la BD real
(`jaco`) antes del fix: 3/4 y 3/3 tests fallaban respectivamente.

---

## 13. Matriz de tests

**Caso app (§4)**
- anular venta simple → `sale_quantity` vuelve al valor previo, `stock` al original
- anular venta con regalía → `royalties_quantity` revertido y `assigned_product_movements` eliminado
- anular venta con cambio → ídem `changes_quantity`
- anular venta con línea normal + regalía del mismo producto → ambos revertidos, sin doble conteo
- **anular como admin (usuario sin `employee`)** → resuelve la asignación por `sale.employee_id`, no por `Auth`
- asignación inexistente → falla con mensaje claro, sin revertir nada a medias
- reversión que dejaría `sale_quantity` negativo → se recorta a 0 y se registra warning
- el inventario **no** se toca en ningún caso app

**Caso web (§5)**
- anular venta web → `finished_product_inventories.stock` restaurado
- se crea el asiento compensatorio, **no se borra** el `salida` original
- venta con varias líneas y varios inventarios → un compensatorio por asiento
- colisión `reference_id` entre venta #N y devolución #N → no compensa el ajeno
- el cajero no tiene asignación → no se toca ningún `detail_assigned_products`

**Reglas de negocio (§0)**
- R1: venta de ayer → rechazada (aunque no haya cuadre)
- R2: cuadre `completed` del día → rechazada
- **R2: cuadre `pending` del día → rechazada** (el caso que cubre §4.4)
- R3: venta de contado en estado `paid` → **se anula correctamente**
- R4: venta a crédito sin pagos → se anula y la CxC queda en `CANCELLED` con `cancelled_at`
- R5: venta a crédito con un pago registrado → rechazada, y la CxC sigue `PENDING`
- **R6: venta a crédito con abono inicial (`cash_amount > 0`, 0 filas en `payments`) → se anula**, y la respuesta/UI informa el monto a devolver
- R7: venta con `created_at` de hoy pero `sale_date` retrofechada (histórico) → rechazada
- **R7 web: `save()` ignora la fecha enviada por el cliente y persiste `now()`** (test de componente Livewire enviando otra fecha)
- R8: el asiento compensatorio es de tipo `DEVOLUCION`, no `ENTRADA`
- venta facturada → rechazada
- CxC cancelada no aparece en `getAccountReceivable` ni cuenta para el límite de cuentas activas del cliente

**Transversales (§7–§9)**
- anular dos veces (o dos DELETE concurrentes) → revierte una sola vez, responde 200
- vendedor intentando anular la venta de otro → 403
- cajero intentando anular una venta que no creó → 403
- fallo a mitad de la reversión → rollback total, nada queda revertido ni la venta cambia de estado
- venta anulada deja de contar en cuadre, ranking y listado del día
- **reintento del móvil con el `client_request_uuid` de una venta anulada → responde "ya registrada", no la re-crea ni la revive**
