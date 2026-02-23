<?php

namespace App\Services;

use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Enums\AssignedProductMovementTypeEnum;
use Illuminate\Support\Facades\DB;
use Exception;

class AssignedProductMovementService
{
    /**
     * Create a new movement (Change or Royalty) and update the assigned product detail accumulator.
     */
    public function createMovement(int $detailId, string $type, float $quantity, ?string $note = null): AssignedProductMovement
    {
        return DB::transaction(function () use ($detailId, $type, $quantity, $note) {
            // 1. Find DetailAssignedProduct
            $detail = DetailAssignedProduct::find($detailId);

            if (!$detail) {
                throw new Exception("El detalle del producto asignado no existe.");
            }

            // Check stock availability
            if ($detail->stock < $quantity) {
                 throw new Exception("Stock insuficiente para realizar el movimiento. Stock actual: {$detail->stock}");
            }

            // 2. Create Movement
            $movement = AssignedProductMovement::create([
                'detail_assigned_product_id' => $detail->id,
                'type' => AssignedProductMovementTypeEnum::from($type),
                'quantity' => $quantity,
                'note' => $note,
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
            $detail = $movement->detailAssignedProduct;
            
            // Revert accumulator
            if ($movement->type === AssignedProductMovementTypeEnum::CHANGE) {
                $detail->changes_quantity -= $movement->quantity;
            } else {
                $detail->royalties_quantity -= $movement->quantity;
            }
            $detail->save();
            
            $movement->delete();
        });
    }
}
