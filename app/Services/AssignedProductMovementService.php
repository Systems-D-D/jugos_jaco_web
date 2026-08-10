<?php

namespace App\Services;

use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Enums\AssignedProductMovementTypeEnum;
use App\Exceptions\InsufficientAssignedStockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Exception;

class AssignedProductMovementService
{
    /**
     * Create a new movement (Change or Royalty) and update the assigned product detail accumulator.
     *
     * @param string|null $clientRequestUuid Clave de idempotencia: si ya existe un
     *        movimiento con este uuid se devuelve el existente sin volver a acumular.
     */
    public function createMovement(
        int $detailId,
        string $type,
        float $quantity,
        ?string $note = null,
        ?int $saleId = null,
        ?string $clientRequestUuid = null
    ): AssignedProductMovement {
        if ($clientRequestUuid) {
            $existing = AssignedProductMovement::where('client_request_uuid', $clientRequestUuid)->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            return $this->persistMovement($detailId, $type, $quantity, $note, $saleId, $clientRequestUuid);
        } catch (QueryException $qe) {
            // Reintento concurrente con el mismo uuid: el índice único ya bloqueó
            // la segunda inserción, devolvemos el movimiento que sí quedó registrado.
            if ($clientRequestUuid) {
                $existing = AssignedProductMovement::where('client_request_uuid', $clientRequestUuid)->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $qe;
        }
    }

    private function persistMovement(
        int $detailId,
        string $type,
        float $quantity,
        ?string $note,
        ?int $saleId,
        ?string $clientRequestUuid
    ): AssignedProductMovement {
        return DB::transaction(function () use ($detailId, $type, $quantity, $note, $saleId, $clientRequestUuid) {
            // 1. Find DetailAssignedProduct
            // lockForUpdate: el acumulador se lee y se reescribe, sin bloqueo dos
            // movimientos concurrentes se pisan y sólo uno queda contabilizado.
            $detail = DetailAssignedProduct::lockForUpdate()->find($detailId);

            if (!$detail) {
                throw new Exception("El detalle del producto asignado no existe.");
            }

            // Check stock availability
            if ($detail->stock < $quantity) {
                 throw new InsufficientAssignedStockException("Stock insuficiente para realizar el movimiento. Stock actual: {$detail->stock}");
            }

            // 2. Create Movement
            $movement = AssignedProductMovement::create([
                'detail_assigned_product_id' => $detail->id,
                'type' => AssignedProductMovementTypeEnum::from($type),
                'quantity' => $quantity,
                'note' => $note,
                'sale_id' => $saleId,
                'client_request_uuid' => $clientRequestUuid,
                'created_by' => auth()->id(),
            ]);

            // 3. Update Accumulator
            if ($movement->type === AssignedProductMovementTypeEnum::CHANGE) {
                $detail->changes_quantity += $quantity;
            } else {
                $detail->royalties_quantity += $quantity;
            }
            $detail->save();

            return $movement;
        });
    }

    /**
     * Delete a movement and revert the assigned product detail accumulator.
     */
    public function deleteMovement(int $movementId): void
    {
        DB::transaction(function () use ($movementId) {
            $movement = AssignedProductMovement::findOrFail($movementId);
            $detail = DetailAssignedProduct::lockForUpdate()
                ->findOrFail($movement->detail_assigned_product_id);

            // Revert accumulator (nunca por debajo de 0)
            if ($movement->type === AssignedProductMovementTypeEnum::CHANGE) {
                $detail->changes_quantity = max(0, $detail->changes_quantity - $movement->quantity);
            } else {
                $detail->royalties_quantity = max(0, $detail->royalties_quantity - $movement->quantity);
            }
            $detail->save();
            
            $movement->delete();
        });
    }
}
