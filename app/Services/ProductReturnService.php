<?php

namespace App\Services;

use App\Enums\ProductReturnTypeEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Models\FinishedProductInventory;
use App\Models\ProductReturn;
use Illuminate\Support\Facades\Log;

class ProductReturnService
{
    /**
     * Registra el movimiento de inventario de una devolución.
     * La cantidad puede ser negativa (corrección de conteo de sobrantes):
     * el tipo de movimiento se invierte y se usa el valor absoluto.
     */
    public function registerInventoryMovement(ProductReturn $productReturn): void
    {
        if (! $productReturn->affects_inventory) {
            Log::info('Devolución creada sin afectar inventario', [
                'product_return_id' => $productReturn->id,
                'affects_inventory' => false,
            ]);

            return;
        }

        try {
            $inventory = $this->findInventory($productReturn);

            if (! $inventory) {
                Log::error('No se encontró el inventario del producto para la devolución', [
                    'product_return_id' => $productReturn->id,
                    'product_id' => $productReturn->product_id,
                    'branch_id' => $productReturn->employee->branch_id,
                ]);

                return;
            }

            [$movementType, $quantity] = $this->movementFor($productReturn, reverse: false);

            $description = sprintf(
                'Devolución de producto (%s) - Empleado: %s - Motivo: %s',
                $productReturn->type->getLabel(),
                $productReturn->employee->full_name,
                $productReturn->reason
            );

            (new ManagementInventoryService)->processMovement(
                model: $inventory,
                quantity: $quantity,
                type: $movementType->value,
                description: $description,
                referenceId: $productReturn->id,
                referenceType: ProductReturn::class,
            );

            Log::info('Movimiento de inventario registrado exitosamente', [
                'product_return_id' => $productReturn->id,
                'movement_type' => $movementType->value,
                'quantity' => $quantity,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al registrar movimiento de inventario', [
                'product_return_id' => $productReturn->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Revierte el movimiento de inventario de una devolución.
     *
     * $strict = false (por defecto): conserva el comportamiento histórico de
     * ProductReturnResource, que registra el error y deja continuar el borrado.
     * $strict = true: relanza el error para que el llamador revierta su
     * transacción. Si no existe inventario se omite también en modo estricto,
     * igual que registerInventoryMovement() al crear: la devolución nunca
     * generó movimiento y no hay nada que revertir.
     */
    public function reverseInventoryMovement(ProductReturn $productReturn, bool $strict = false): void
    {
        if (! $productReturn->affects_inventory) {
            Log::info('Devolución eliminada sin revertir inventario', [
                'product_return_id' => $productReturn->id,
                'affects_inventory' => false,
            ]);

            return;
        }

        try {
            $inventory = $this->findInventory($productReturn);

            if (! $inventory) {
                Log::error('No se encontró inventario para revertir el movimiento', [
                    'product_id' => $productReturn->product_id,
                    'branch_id' => $productReturn->employee->branch_id,
                    'product_return_id' => $productReturn->id,
                ]);

                return;
            }

            [$movementType, $quantity] = $this->movementFor($productReturn, reverse: true);

            $description = "Reversión de devolución #{$productReturn->id} - {$productReturn->type->getLabel()} - {$productReturn->product->name}";

            (new ManagementInventoryService)->processMovement(
                $inventory,
                $quantity,
                $movementType->value,
                $description,
                $productReturn->id,
                ProductReturn::class,
            );

            Log::info('Movimiento de inventario revertido exitosamente', [
                'product_return_id' => $productReturn->id,
                'movement_type' => $movementType->value,
                'quantity' => $quantity,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al revertir movimiento de inventario', [
                'product_return_id' => $productReturn->id,
                'error' => $e->getMessage(),
            ]);

            if ($strict) {
                throw $e;
            }
        }
    }

    private function findInventory(ProductReturn $productReturn): ?FinishedProductInventory
    {
        return FinishedProductInventory::where('product_id', $productReturn->product_id)
            ->where('branch_id', $productReturn->employee->branch_id)
            ->first();
    }

    /**
     * Tipo de movimiento y cantidad absoluta para una devolución.
     *
     *   RETURNED +q → ENTRADA   RETURNED −q → SALIDA
     *   DAMAGED  +q → DANADO    DAMAGED  −q → ENTRADA
     * La reversión aplica el inverso: ENTRADA↔SALIDA, DANADO→ENTRADA.
     *
     * @return array{0: TypeInventoryManagementEnum, 1: float}
     */
    private function movementFor(ProductReturn $productReturn, bool $reverse): array
    {
        $quantity = (float) $productReturn->quantity;
        $positive = $quantity > 0;

        $forward = match ($productReturn->type) {
            ProductReturnTypeEnum::RETURNED => $positive ? TypeInventoryManagementEnum::ENTRADA : TypeInventoryManagementEnum::SALIDA,
            ProductReturnTypeEnum::DAMAGED => $positive ? TypeInventoryManagementEnum::DANADO : TypeInventoryManagementEnum::ENTRADA,
        };

        $type = ! $reverse ? $forward : match ($forward) {
            TypeInventoryManagementEnum::ENTRADA => TypeInventoryManagementEnum::SALIDA,
            TypeInventoryManagementEnum::SALIDA, TypeInventoryManagementEnum::DANADO => TypeInventoryManagementEnum::ENTRADA,
            default => throw new \LogicException('Movimiento de devolución no soportado: '.$forward->value),
        };

        return [$type, abs($quantity)];
    }
}
