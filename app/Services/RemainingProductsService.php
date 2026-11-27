<?php

namespace App\Services;

use App\Enums\ProductReturnTypeEnum;
use App\Enums\ReconciliationStatusEnum;
use App\Exceptions\RemainingProductCountException;
use App\Models\AssignedProduct;
use App\Models\DailySalesReconciliation;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\ProductReturn;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regla única del conteo de productos sobrantes. La usan la página del
 * cajero (RemainingProductsCount) y el cuadre (CreateReconciliation).
 *
 * Cada conteo guarda la DIFERENCIA contra lo ya registrado como una
 * devolución propia (con signo), de modo que corregir 5 → 3 deja +5 y −2 y
 * el inventario recibe exactamente 3 en neto.
 */
class RemainingProductsService
{
    public const REASON_COUNT = 'Conteo de sobrantes';

    public const REASON_CORRECTION = 'Corrección de conteo de sobrantes';

    public function __construct(private ProductReturnService $productReturnService) {}

    /**
     * Filas de la asignación del empleado en la fecha, sin montos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForEmployee(int $employeeId, string $date): array
    {
        $assignment = AssignedProduct::with(['details.product'])
            ->where('employee_id', $employeeId)
            ->assignmentsDate($date)
            ->first();

        if (! $assignment) {
            return [];
        }

        return $assignment->details
            ->filter(fn (DetailAssignedProduct $detail) => (float) $detail->quantity > 0)
            ->map(fn (DetailAssignedProduct $detail) => $this->toRow($detail))
            ->values()
            ->all();
    }

    public function findReconciliation(int $employeeId, string $date): ?DailySalesReconciliation
    {
        return DailySalesReconciliation::getForEmployeeAndDate($employeeId, $date);
    }

    public function isClosed(int $employeeId, string $date): bool
    {
        return $this->findReconciliation($employeeId, $date)?->status === ReconciliationStatusEnum::COMPLETED;
    }

    /**
     * Máximo que puede figurar como devuelto: asignado − vendido − cambios −
     * regalías. Nunca negativo (hay detalles anteriores a 93ec558 con
     * sale_quantity inflado).
     */
    public static function maxReturnable(DetailAssignedProduct $detail): float
    {
        return max(0.0, round(
            (float) $detail->quantity
            - (float) $detail->sale_quantity
            - (float) $detail->changes_quantity
            - (float) $detail->royalties_quantity,
            2
        ));
    }

    /**
     * Registra la cantidad devuelta de un detalle. Devuelve la devolución
     * creada, o null si la cantidad ya era la registrada.
     *
     * @throws RemainingProductCountException
     */
    public function registerCount(int $employeeId, string $date, int $detailId, float $quantity, User $countedBy): ?ProductReturn
    {
        return DB::transaction(function () use ($employeeId, $date, $detailId, $quantity, $countedBy) {
            // Orden de bloqueo fijo: cuadre → detalle (igual en todo el servicio).
            $reconciliation = $this->lockOpenReconciliation($employeeId, $date, $countedBy);
            $detail = $this->lockDetail($employeeId, $date, $detailId);

            return $this->applyCount($reconciliation, $detail, $employeeId, $quantity, $countedBy);
        });
    }

    /**
     * Marca como devuelto todo el sobrante de los detalles que aún no tienen
     * conteo (returned_quantity = 0). Todo o nada.
     *
     * @return int cantidad de detalles procesados
     *
     * @throws RemainingProductCountException
     */
    public function returnAllRemaining(int $employeeId, string $date, User $countedBy): int
    {
        return DB::transaction(function () use ($employeeId, $date, $countedBy) {
            $reconciliation = $this->lockOpenReconciliation($employeeId, $date, $countedBy);

            $details = DetailAssignedProduct::whereHas('assignedProduct', fn ($query) => $query
                ->where('employee_id', $employeeId)
                ->whereDate('date', $date))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $processed = 0;

            foreach ($details as $detail) {
                if ((float) $detail->returned_quantity != 0.0) {
                    continue; // ya contado: no se sobrescribe
                }

                $remaining = self::maxReturnable($detail);

                if ($remaining <= 0) {
                    continue;
                }

                $this->applyCount($reconciliation, $detail, $employeeId, $remaining, $countedBy);
                $processed++;
            }

            return $processed;
        });
    }

    /**
     * Borra una devolución revirtiendo su movimiento de inventario y, si vino
     * del conteo de sobrantes, el ajuste de returned_quantity del detalle.
     *
     * @throws RemainingProductCountException
     */
    public function deleteReturn(ProductReturn $productReturn): void
    {
        DB::transaction(function () use ($productReturn) {
            $locked = ProductReturn::whereKey($productReturn->id)->lockForUpdate()->firstOrFail();

            if ($locked->reconciliation_id) {
                $reconciliation = DailySalesReconciliation::whereKey($locked->reconciliation_id)->lockForUpdate()->first();

                if ($reconciliation?->status === ReconciliationStatusEnum::COMPLETED) {
                    throw RemainingProductCountException::reconciliationClosed();
                }
            }

            if ($locked->detail_assigned_product_id) {
                $detail = DetailAssignedProduct::whereKey($locked->detail_assigned_product_id)->lockForUpdate()->first();

                if ($detail) {
                    $newReturned = round((float) $detail->returned_quantity - (float) $locked->quantity, 2);

                    if ($newReturned < 0) {
                        throw RemainingProductCountException::cannotUndoBelowZero();
                    }

                    $max = self::maxReturnable($detail);
                    if ($newReturned > $max) {
                        throw RemainingProductCountException::invalidQuantity($max);
                    }

                    $detail->update(['returned_quantity' => $newReturned]);
                }
            }

            try {
                $this->productReturnService->reverseInventoryMovement($locked, strict: true);
            } catch (\Throwable $e) {
                Log::error('Conteo de sobrantes: falló la reversión de inventario al borrar', [
                    'product_return_id' => $locked->id,
                    'error' => $e->getMessage(),
                ]);

                throw RemainingProductCountException::inventoryFailed($e);
            }

            $locked->delete();
        });
    }

    private function toRow(DetailAssignedProduct $detail): array
    {
        $assigned = (float) $detail->quantity;
        $sold = (float) $detail->sale_quantity;
        $returned = (float) $detail->returned_quantity;
        $changes = (float) $detail->changes_quantity;
        $royalties = (float) $detail->royalties_quantity;

        return [
            'id' => $detail->id,
            'product_id' => $detail->product_id,
            'product_name' => $detail->product->name ?? 'Producto no encontrado',
            'product_code' => $detail->product->code ?? 'N/A',
            'quantity_assigned' => $assigned,
            'quantity_sold' => $sold,
            'returned_quantity' => $returned,
            'changes_quantity' => $changes,
            'royalties_quantity' => $royalties,
            'remaining' => round($assigned - $sold - $returned - $changes - $royalties, 2),
            'max_returnable' => self::maxReturnable($detail),
        ];
    }

    /**
     * Cuadre del empleado y la fecha, bloqueado. Si no existe se crea
     * pendiente con totales en 0 (decisión 1); si está cerrado se rechaza.
     */
    private function lockOpenReconciliation(int $employeeId, string $date, User $user): DailySalesReconciliation
    {
        $reconciliation = $this->lockExistingReconciliation($employeeId, $date);

        if (! $reconciliation) {
            try {
                $reconciliation = $this->createPendingReconciliation($employeeId, $date, $user);
            } catch (QueryException $e) {
                // Otro proceso creó el cuadre entre nuestra lectura y el insert:
                // el índice único lo impidió; usamos el que ganó.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $reconciliation = $this->lockExistingReconciliation($employeeId, $date) ?? throw $e;
            }
        }

        if ($reconciliation->status === ReconciliationStatusEnum::COMPLETED) {
            throw RemainingProductCountException::reconciliationClosed();
        }

        return $reconciliation;
    }

    private function lockExistingReconciliation(int $employeeId, string $date): ?DailySalesReconciliation
    {
        return DailySalesReconciliation::where('employee_id', $employeeId)
            ->whereDate('reconciliation_date', $date)
            ->lockForUpdate()
            ->first();
    }

    private function createPendingReconciliation(int $employeeId, string $date, User $user): DailySalesReconciliation
    {
        // Los totales quedan en 0: los recalcula y guarda el cuadre al abrirse
        // (CreateReconciliation::syncPendingTotals).
        return DailySalesReconciliation::create([
            'employee_id' => $employeeId,
            'cashier_id' => $user->id,
            'branch_id' => $user->employee?->branch_id ?? Employee::findOrFail($employeeId)->branch_id,
            'reconciliation_date' => $date,
            'total_cash_sales' => 0,
            'cash_sales' => 0,
            'total_credit_sales' => 0,
            'deposit_sales' => 0,
            'total_sales' => 0,
            'cash_collections' => 0,
            'deposit_collections' => 0,
            'total_collections' => 0,
            'total_cash_received' => 0,
            'total_deposits' => 0,
            'total_cash_expected' => 0,
            'total_deposit_expected' => 0,
            'cash_difference' => 0,
            'status' => ReconciliationStatusEnum::PENDING,
            'product_shortage_total' => 0,
        ]);
    }

    private function lockDetail(int $employeeId, string $date, int $detailId): DetailAssignedProduct
    {
        $detail = DetailAssignedProduct::whereKey($detailId)
            ->whereHas('assignedProduct', fn ($query) => $query
                ->where('employee_id', $employeeId)
                ->whereDate('date', $date))
            ->lockForUpdate()
            ->first();

        if (! $detail) {
            throw RemainingProductCountException::detailNotInAssignment();
        }

        return $detail;
    }

    private function applyCount(
        DailySalesReconciliation $reconciliation,
        DetailAssignedProduct $detail,
        int $employeeId,
        float $quantity,
        User $countedBy
    ): ?ProductReturn {
        $max = self::maxReturnable($detail);

        if ($quantity < 0 || round($quantity, 2) > $max) {
            throw RemainingProductCountException::invalidQuantity($max);
        }

        $current = (float) $detail->returned_quantity;
        $difference = round($quantity - $current, 2);

        if ($difference == 0.0) {
            return null;
        }

        $productReturn = ProductReturn::create([
            'product_id' => $detail->product_id,
            'employee_id' => $employeeId,
            'counted_by' => $countedBy->id,
            'detail_assigned_product_id' => $detail->id,
            'reconciliation_id' => $reconciliation->id,
            'quantity' => $difference,
            'type' => ProductReturnTypeEnum::RETURNED,
            'reason' => ($current == 0.0 && $difference > 0) ? self::REASON_COUNT : self::REASON_CORRECTION,
            'affects_inventory' => true,
        ]);

        $detail->update(['returned_quantity' => round($quantity, 2)]);

        $this->moveInventory($productReturn, $detail);

        return $productReturn;
    }

    private function moveInventory(ProductReturn $productReturn, DetailAssignedProduct $detail): void
    {
        try {
            $this->productReturnService->registerInventoryMovement($productReturn);
        } catch (\Throwable $e) {
            Log::error('Conteo de sobrantes: falló el movimiento de inventario', [
                'product_return_id' => $productReturn->id,
                'detail_assigned_product_id' => $detail->id,
                'reconciliation_id' => $productReturn->reconciliation_id,
                'error' => $e->getMessage(),
            ]);

            throw RemainingProductCountException::inventoryFailed($e);
        }
    }
}
