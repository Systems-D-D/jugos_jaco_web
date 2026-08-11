<?php

namespace App\Services;

use App\Models\FinishedProductInventory;
use App\Models\ProductReturn;
use App\Services\ManagementInventoryService;
use App\Enums\TypeInventoryManagementEnum;
use App\Enums\ProductReturnTypeEnum;
use Illuminate\Support\Facades\Log;

class ProductReturnService
{
    /**
     * Registra el movimiento de inventario al crear una devolución
     */
    public function registerInventoryMovement(ProductReturn $productReturn): void
    {
        // Verificar si la devolución debe afectar el inventario
        if (!$productReturn->affects_inventory) {
            Log::info('Devolución creada sin afectar inventario', [
                'product_return_id' => $productReturn->id,
                'affects_inventory' => false
            ]);
            return;
        }

        try {
            // Obtener el inventario del producto en la sucursal del empleado
            $inventory = FinishedProductInventory::where([
                'product_id' => $productReturn->product_id,
                'branch_id' => $productReturn->employee->branch_id
            ])->first();

            if (!$inventory) {
                // Log error si no se encuentra el inventario
                Log::error('No se encontró el inventario del producto para la devolución', [
                    'product_return_id' => $productReturn->id,
                    'product_id' => $productReturn->product_id,
                    'branch_id' => $productReturn->employee->branch_id
                ]);
                return;
            }

            // Determinar el tipo de movimiento basado en el tipo de devolución
            $movementType = $productReturn->type->value === 'returned' 
                ? TypeInventoryManagementEnum::ENTRADA->value 
                : TypeInventoryManagementEnum::DANADO->value;

            // Crear descripción del movimiento
            $description = sprintf(
                'Devolución de producto (%s) - Empleado: %s - Motivo: %s',
                $productReturn->type->getLabel(),
                $productReturn->employee->full_name,
                $productReturn->reason
            );

            // Registrar el movimiento de inventario
            $managementService = new ManagementInventoryService();
            $managementService->processMovement(
                model: $inventory,
                quantity: $productReturn->quantity,
                type: $movementType,
                description: $description,
                referenceId: $productReturn->id,
                referenceType: ProductReturn::class,
            );

            Log::info('Movimiento de inventario registrado exitosamente', [
                'product_return_id' => $productReturn->id,
                'movement_type' => $movementType,
                'quantity' => $productReturn->quantity
            ]);

        } catch (\Exception $e) {
            Log::error('Error al registrar movimiento de inventario', [
                'product_return_id' => $productReturn->id,
                'error' => $e->getMessage()
            ]);
            throw $e; // Re-lanzamos la excepción para evitar crear la devolución si falla el inventario
        }
    }

    /**
     * Revierte el movimiento de inventario al eliminar una devolución
     */
    public function reverseInventoryMovement(ProductReturn $productReturn): void
    {
        // Verificar si la devolución afecta el inventario
        if (!$productReturn->affects_inventory) {
            Log::info('Devolución eliminada sin revertir inventario', [
                'product_return_id' => $productReturn->id,
                'affects_inventory' => false
            ]);
            return;
        }

        try {
            // Obtener el inventario basado en el producto y la sucursal del empleado
            $inventory = FinishedProductInventory::where('product_id', $productReturn->product_id)
                ->where('branch_id', $productReturn->employee->branch_id)
                ->first();

            if (!$inventory) {
                Log::error('No se encontró inventario para revertir el movimiento', [
                    'product_id' => $productReturn->product_id,
                    'branch_id' => $productReturn->employee->branch_id,
                    'product_return_id' => $productReturn->id
                ]);
                return;
            }

            // Determinar el tipo de movimiento inverso
            $movementType = $this->getInverseMovementType($productReturn->type);
            
            // Crear descripción del movimiento
            $description = "Reversión de devolución #{$productReturn->id} - {$productReturn->type->getLabel()} - {$productReturn->product->name}";
            
            // Registrar el movimiento de inventario inverso
            $managementService = new ManagementInventoryService();
            $managementService->processMovement(
                $inventory,
                $productReturn->quantity,
                $movementType->value,
                $description,
                $productReturn->id,
                ProductReturn::class,
            );

            Log::info('Movimiento de inventario revertido exitosamente', [
                'product_return_id' => $productReturn->id,
                'movement_type' => $movementType->value,
                'quantity' => $productReturn->quantity
            ]);

        } catch (\Exception $e) {
            Log::error('Error al revertir movimiento de inventario', [
                'product_return_id' => $productReturn->id,
                'error' => $e->getMessage()
            ]);
            // No lanzamos la excepción para permitir que la eliminación continúe
        }
    }

    /**
     * Obtiene el tipo de movimiento inverso según el tipo de devolución
     */
    private function getInverseMovementType(ProductReturnTypeEnum $returnType): TypeInventoryManagementEnum
    {
        return match($returnType) {
            ProductReturnTypeEnum::DAMAGED => TypeInventoryManagementEnum::ENTRADA, // Damaged disminuyó, ahora aumentamos
            ProductReturnTypeEnum::RETURNED => TypeInventoryManagementEnum::SALIDA, // Returned aumentó, ahora disminuimos
        };
    }
}