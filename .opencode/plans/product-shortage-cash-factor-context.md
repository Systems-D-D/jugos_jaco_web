# DevFlow Context

**Request:** Agregar al cuadre (reconciliacion) el calculo del efectivo del producto faltante basado en una escala de precios TypePrice seleccionable. Integrado en la tabla existente de Productos Sobrantes.
**Slug:** product-shortage-cash-factor
**Feature Type:** fullstack
**Stack Mode:** no
**Selected Mockup:** N/A

## Stack Profile
[To be detected by Architect]

## Goal
En la tabla existente de "Productos Sobrantes" del cuadre: agregar columna "Efectivo Prod. Faltante" que calcule `Sobrante x Precio(TypePrice seleccionado)`, con selector de TypePrice en el encabezado. El total se muestra arriba de "Efectivo Esperado" y se suma a este. Cambios visuales: eliminar columna "Codigo" (pasa a badge en nombre), igualar altura de tabla Sobrantes con Ventas del Dia.

## Definition of Done
- [ ] Migracion con columnas `product_shortage_total` (decimal 10,2 default 0) y `type_price_id` (foreignId nullable -> types_prices) en `daily_sales_reconciliations`
- [ ] Selector TypePrice en encabezado de seccion "Productos Sobrantes"
- [ ] Columna "Efectivo Prod. Faltante" en tabla de Sobrantes (calculo: Sobrante x Precio)
- [ ] Columna "Codigo" eliminada de la tabla, codigo mostrado como badge pequeno en nombre del producto
- [ ] Total de efectivo faltante visible ARRIBA de "Efectivo Esperado", sumado a este
- [ ] Altura de tabla Productos Sobrantes igualada a la altura de Ventas del Dia
- [ ] Persistencia de `product_shortage_total` y `type_price_id` al guardar el cuadre
- [ ] Vista de detalle (ViewDailySalesReconciliation) muestra total faltante y TypePrice usado
- [ ] Tests del calculo y persistencia

## Constraints
- Integrado en tabla de Sobrantes existente (no nuevo apartado separado)
- Total faltante SI modifica Efectivo Esperado (se suma)
- Selector TypePrice en encabezado de seccion Productos Sobrantes
- Sin stock negativo: `Sobrante = Asignado - Vendido - Retornado - Cambios - Regalias`
- Calculo: `Efectivo = Sobrante x Precio(TypePrice)` para cada producto con Sobrante > 0

## Edge Cases
- Producto sin precio en la escala seleccionada -> mostrar $0.00
- Sobrante <= 0 -> columna vacia o $0.00
- Sin productos asignados -> ocultar selector de TypePrice
- Sin TypePrices registrados -> ocultar seccion de faltante
- Cambio de TypePrice -> recalcular todos los montos en tiempo real

## Assumptions
- El precio se obtiene de `ProductPrice` donde `type_price_id` = seleccionado y `product_id` = producto de la fila
- La unidad del producto se toma de `DetailAssignedProduct` o de la unidad base del `AssignedProduct`
- El badge de codigo usa un estilo pequeno (text-xs, bg-gray-100)

## Impact
- **Modifies existing behavior:** Si -- Efectivo Esperado ahora incluye el total faltante
- **Affected features:** CreateReconciliation (Livewire + blade), ViewDailySalesReconciliation (Filament), DailySalesReconciliation (model + migration)

## Architect Findings
[To be filled by Architect]
