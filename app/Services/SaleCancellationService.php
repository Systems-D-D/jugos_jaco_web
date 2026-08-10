<?php

namespace App\Services;

use App\Enums\AccountReceivableStatusEnum;
use App\Enums\SaleStatusEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Exceptions\SaleCancellationException;
use App\Models\AssignedProduct;
use App\Models\DailySalesReconciliation;
use App\Models\DetailAssignedProduct;
use App\Models\ManagementInventory;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Anula una venta siguiendo las reglas de
 * docs/devflow/specs/2026-08-10-sale-deletion-analysis.md (R1-R8).
 *
 * Una venta nunca se borra físicamente: se anula (status = CANCELLED) y se
 * revierten sus efectos. Qué se revierte depende de evidencia, no de un
 * campo de "canal" persistido — ver §6 del análisis:
 *   - assigned_product_movements ligados por sale_id (regalías/cambios):
 *     siempre, es un vínculo directo sin ambigüedad de origen.
 *   - sale_quantity de detail_assigned_products: sólo para las líneas de la
 *     venta que NO tengan un asiento de inventario asociado (evidencia de
 *     venta creada desde la app).
 *   - asientos de management_inventory con reference_type=Sale::class: para
 *     las líneas que sí lo tengan (evidencia de venta creada desde la web).
 */
class SaleCancellationService
{
    public function __construct(
        private readonly AssignedProductMovementService $movementService,
        private readonly ManagementInventoryService $inventoryService,
    ) {
    }

    /**
     * @throws SaleCancellationException Precondición no cumplida (venta
     *         facturada, fuera de la ventana del mismo día, cuadre ya
     *         existente, pagos ya registrados) o evidencia insuficiente
     *         para revertir con seguridad.
     */
    public function cancel(Sale $sale, ?string $reason = null): Sale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            // Idempotente: un reintento (móvil, doble clic) no debe fallar ni
            // revertir dos veces. Debe ir DESPUÉS del lockForUpdate: dos
            // anulaciones concurrentes de la misma venta deben serializarse
            // aquí, no ambas leer status=CONFIRMED a la vez.
            if ($sale->isCancelled()) {
                return $sale;
            }

            $this->assertCanBeCancelled($sale);

            $inventoryMovements = $this->fetchSaleInventoryMovements($sale);

            $this->revertAssignedProductMovements($sale);
            $this->revertAssignedProductSaleQuantity($sale, $inventoryMovements);
            $this->revertInventoryMovements($sale, $inventoryMovements);
            $this->cancelAccountReceivableIfCredit($sale);

            $sale->update([
                'status' => SaleStatusEnum::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
            ]);

            return $sale->fresh();
        });
    }

    private function assertCanBeCancelled(Sale $sale): void
    {
        if ($sale->isInvoiced()) {
            throw new SaleCancellationException(
                'Una venta facturada no se anula, se emite nota de crédito.',
                409
            );
        }

        $today = Carbon::today();
        $saleDate = $sale->sale_date instanceof Carbon ? $sale->sale_date : Carbon::parse($sale->sale_date);
        $createdAt = $sale->created_at instanceof Carbon ? $sale->created_at : Carbon::parse($sale->created_at);

        // R1/R7: exige sale_date Y created_at de hoy. sale_date preserva la
        // coherencia con el cuadre (agrupa por esa columna); created_at
        // cierra el hueco de una venta retrofechada.
        if (!$saleDate->isSameDay($today) || !$createdAt->isSameDay($today)) {
            throw new SaleCancellationException(
                'Sólo se pueden anular ventas del mismo día.',
                422
            );
        }

        // R2: cualquier cuadre del día bloquea, sin filtrar por estado (un
        // cuadre "pending" ya pudo haber registrado devoluciones que
        // dependen de sale_quantity — ver §4.4 del análisis).
        if (DailySalesReconciliation::existsForEmployeeAndDate($sale->employee_id, $saleDate->toDateString())) {
            throw new SaleCancellationException(
                'El día ya tiene cuadre; no se puede anular la venta.',
                422
            );
        }

        // R5: cualquier pago registrado bloquea. cash_amount (abono inicial,
        // R6) NO se considera aquí a propósito: no genera fila en payments.
        $accountReceivable = $sale->accountReceivable;
        if ($accountReceivable && $accountReceivable->payments()->exists()) {
            throw new SaleCancellationException(
                'La venta tiene pagos registrados sobre su cuenta por cobrar; no se puede anular.',
                422
            );
        }
    }

    /**
     * Regalías y cambios ligados a la venta. El vínculo es directo
     * (assigned_product_movements.sale_id): aplica siempre, sin ambigüedad
     * de origen, sea la venta de la app o de la web.
     */
    private function revertAssignedProductMovements(Sale $sale): void
    {
        foreach ($sale->assignedProductMovements as $movement) {
            $this->movementService->deleteMovement($movement->id);
        }
    }

    /**
     * sale_quantity de las líneas que vinieron de una asignación de producto
     * (venta creada desde la app). La asignación se resuelve por
     * (sale.employee_id, sale.sale_date) — NUNCA por el usuario autenticado
     * que anula: puede ser un admin sin `employee`, o distinto del vendedor
     * que hizo la venta.
     *
     * Sólo procesa líneas sin evidencia de inventario: un producto con un
     * asiento de management_inventory referenciando esta venta pertenece al
     * flujo web, aunque el vendedor tenga una asignación ese día con el
     * mismo producto.
     */
    private function revertAssignedProductSaleQuantity(Sale $sale, Collection $inventoryMovements): void
    {
        $inventoryHandledProductIds = $inventoryMovements
            ->pluck('model.product_id')
            ->filter()
            ->unique()
            ->all();

        $linesByProduct = $sale->details
            ->whereNotIn('product_id', $inventoryHandledProductIds)
            ->groupBy('product_id');

        if ($linesByProduct->isEmpty()) {
            return;
        }

        $assignedProduct = AssignedProduct::where('employee_id', $sale->employee_id)
            ->whereDate('date', $sale->sale_date)
            ->first();

        // Se resuelven todos los detalles antes de bloquear ninguno, para
        // poder bloquearlos después en orden ascendente por id (mismo
        // criterio que la creación) y no invertir el orden de bloqueo entre
        // una venta concurrente y esta anulación.
        $quantityByDetailId = [];

        foreach ($linesByProduct as $productId => $lines) {
            $detail = $assignedProduct
                ? DetailAssignedProduct::where('assigned_products_id', $assignedProduct->id)
                    ->where('product_id', $productId)
                    ->first()
                : null;

            if (!$detail) {
                // Silent skip prohibido (§4.2 del análisis): sin asignación
                // ni evidencia de inventario no hay forma segura de saber
                // qué se descontó. Se aborta toda la anulación.
                $productName = $lines->first()->product_name ?? "producto #{$productId}";
                throw new SaleCancellationException(
                    "No se encontró evidencia (asignación de producto ni movimiento de inventario) para revertir la línea '{$productName}' de la venta #{$sale->id}.",
                    422
                );
            }

            $quantityByDetailId[$detail->id] = (float) $lines->sum('quantity');
        }

        ksort($quantityByDetailId);

        foreach ($quantityByDetailId as $detailId => $quantity) {
            $detail = DetailAssignedProduct::lockForUpdate()->findOrFail($detailId);

            $expected = (float) $detail->sale_quantity - $quantity;
            $newSaleQuantity = max(0, $expected);

            if ($newSaleQuantity !== $expected) {
                Log::warning('SaleCancellationService: sale_quantity hubiera quedado negativo, se recortó a 0.', [
                    'detail_assigned_product_id' => $detail->id,
                    'sale_id' => $sale->id,
                ]);
            }

            $detail->update(['sale_quantity' => $newSaleQuantity]);

            // Aserción defensiva del invariante (§4.4): si esto falla hay
            // drift previo ajeno a esta venta; se aborta la transacción
            // completa en vez de dejar un sobrante inconsistente.
            if ((float) $detail->fresh()->stock < 0) {
                throw new SaleCancellationException(
                    "La reversión dejaría stock negativo en el producto asignado #{$detail->id}; anulación abortada.",
                    500
                );
            }
        }
    }

    /**
     * Asientos de inventario que la venta generó (venta creada desde la
     * web): compensa cada SALIDA referenciada por esta venta con un asiento
     * DEVOLUCION (R8). Nunca borra el asiento original — el histórico de
     * management_inventory es un libro de asientos, se compensa, no se edita.
     */
    private function revertInventoryMovements(Sale $sale, Collection $inventoryMovements): void
    {
        foreach ($inventoryMovements as $movement) {
            $model = $movement->model;

            if (!$model) {
                throw new SaleCancellationException(
                    "El inventario referenciado por el movimiento #{$movement->id} de la venta #{$sale->id} ya no existe; anulación abortada.",
                    500
                );
            }

            $this->inventoryService->registerReturn(
                $model,
                (float) $movement->quantity,
                "Anulación de venta #INV-{$sale->id}",
                $sale->id,
                Sale::class,
            );
        }
    }

    /**
     * Asientos SALIDA de management_inventory que esta venta generó. Se
     * calcula una sola vez por anulación y se reutiliza tanto para decidir
     * qué líneas excluir de la reversión de sale_quantity como para generar
     * las compensaciones de inventario.
     */
    private function fetchSaleInventoryMovements(Sale $sale): Collection
    {
        return ManagementInventory::where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', TypeInventoryManagementEnum::SALIDA->value)
            ->with('model')
            ->get();
    }

    /**
     * R4: venta a crédito con cuenta por cobrar sin pagos (ya validado en
     * assertCanBeCancelled) → se cancela la CxC. No se borra: queda como
     * evidencia junto a la venta anulada.
     */
    private function cancelAccountReceivableIfCredit(Sale $sale): void
    {
        $sale->accountReceivable?->update([
            'status' => AccountReceivableStatusEnum::CANCELLED,
            'cancelled_at' => now(),
        ]);
    }
}
