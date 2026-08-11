<?php

namespace App\Services;

use App\Enums\TypeInventoryManagementEnum;
use App\Models\ManagementInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ManagementInventoryService
{
    /**
     * Procesa un movimiento de inventario en base al tipo especificado
     *
     * @param Model $model El modelo al que se aplica el movimiento
     * @param float $quantity La cantidad del movimiento
     * @param string $type El tipo de movimiento (entrada, salida, dañado, devolución)
     * @param string $description Descripción del movimiento
     * @param int|null $referenceId ID de referencia opcional
     * @param class-string|null $referenceType Clase del modelo al que pertenece $referenceId
     *        (p. ej. Sale::class, ProductReturn::class, AssignedProduct::class). Sin esto,
     *        reference_id es ambiguo entre orígenes distintos que reutilizan el mismo id.
     * @return ManagementInventory El registro creado
     * @throws \InvalidArgumentException Si el tipo de movimiento no es válido
     */
    public function processMovement(
        Model $model,
        float $quantity,
        string $type,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ManagementInventory {
        if ($quantity <= 0) throw new \InvalidArgumentException('La cantidad debe ser mayor que cero');

        return match ($type) {
            TypeInventoryManagementEnum::ENTRADA->value => $this->registerEntry($model, $quantity, $description, $referenceId, $referenceType),
            TypeInventoryManagementEnum::SALIDA->value => $this->registerExit($model, $quantity, $description, $referenceId, $referenceType),
            TypeInventoryManagementEnum::DANADO->value => $this->registerDamaged($model, $quantity, $description, $referenceId, $referenceType),
            TypeInventoryManagementEnum::DEVOLUCION->value => $this->registerReturn($model, $quantity, $description, $referenceId, $referenceType),
            default => throw new \InvalidArgumentException('Tipo de movimiento de inventario no válido: ' . $type),
        };
    }

    /**
     * Registra un movimiento de inventario
     *
     * @param Model $model El modelo al que se aplica el movimiento (producto, materia prima, etc.)
     * @param float $quantity La cantidad del movimiento
     * @param TypeInventoryManagementEnum $type El tipo de movimiento (entrada, salida, dañado, devolución)
     * @param string $description Descripción del movimiento
     * @param int|null $referenceId ID de referencia opcional (ej: ID de factura, orden, etc.)
     * @param class-string|null $referenceType Clase del modelo al que pertenece $referenceId
     * @return ManagementInventory El registro creado
     */
    protected function registerMovement(
        Model $model,
        float $quantity,
        TypeInventoryManagementEnum $type,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ManagementInventory {
        if (!method_exists($model, 'movements')) throw new \RuntimeException('El modelo no tiene una relación "movements" definida');

        return $model->movements()->create([
            'description' => $description,
            'quantity' => $quantity,
            'type' => $type->value,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'created_by' => Auth::user()->name,
        ]);
    }

    /**
     * Registra una entrada de inventario
     *
     * @param Model $model El modelo al que se aplica la entrada
     * @param float $quantity La cantidad a incrementar
     * @param string $description Descripción de la entrada
     * @param int|null $referenceId ID de referencia opcional
     * @param class-string|null $referenceType Clase del modelo al que pertenece $referenceId
     * @return ManagementInventory El registro creado
     */
    public function registerEntry(
        Model $model,
        float $quantity,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ManagementInventory {
        return DB::transaction(function () use ($model, $quantity, $description, $referenceId, $referenceType) {
            DB::table($model->getTable())
                ->where('id', $model->id)
                ->increment('stock', $quantity);

            return $this->registerMovement(
                $model,
                $quantity,
                TypeInventoryManagementEnum::ENTRADA,
                $description,
                $referenceId,
                $referenceType
            );
        });
    }

    /**
     * Registra una salida de inventario
     *
     * @param Model $model El modelo al que se aplica la salida
     * @param float $quantity La cantidad a decrementar
     * @param string $description Descripción de la salida
     * @param int|null $referenceId ID de referencia opcional
     * @param class-string|null $referenceType Clase del modelo al que pertenece $referenceId
     * @return ManagementInventory El registro creado
     */
    public function registerExit(
        Model $model,
        float $quantity,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ManagementInventory {
        return DB::transaction(function () use ($model, $quantity, $description, $referenceId, $referenceType) {
            $affected = DB::table($model->getTable())
                ->where('id', $model->id)
                ->where('stock', '>=', $quantity)
                ->decrement('stock', $quantity);

            if ($affected === 0) {
                $currentStock = DB::table($model->getTable())->where('id', $model->id)->value('stock');
                throw new \RuntimeException('No hay suficiente stock disponible. Stock: ' . $currentStock . ', Solicitado: ' . $quantity);
            }

            return $this->registerMovement(
                $model,
                $quantity,
                TypeInventoryManagementEnum::SALIDA,
                $description,
                $referenceId,
                $referenceType
            );
        });
    }

    /**
     * Registra productos dañados en el inventario
     *
     * @param Model $model El modelo al que se aplica el daño
     * @param float $quantity La cantidad dañada
     * @param string $description Descripción del daño
     * @param int|null $referenceId ID de referencia opcional
     * @param class-string|null $referenceType Clase del modelo al que pertenece $referenceId
     * @return ManagementInventory El registro creado
     */
    public function registerDamaged(
        Model $model,
        float $quantity,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ManagementInventory {
        return DB::transaction(function () use ($model, $quantity, $description, $referenceId, $referenceType) {
            $affected = DB::table($model->getTable())
                ->where('id', $model->id)
                ->where('stock', '>=', $quantity)
                ->decrement('stock', $quantity);

            if ($affected === 0) {
                $currentStock = DB::table($model->getTable())->where('id', $model->id)->value('stock');
                throw new \RuntimeException('No hay suficiente stock disponible. Stock: ' . $currentStock . ', Solicitado: ' . $quantity);
            }

            return $this->registerMovement(
                $model,
                $quantity,
                TypeInventoryManagementEnum::DANADO,
                $description,
                $referenceId,
                $referenceType
            );
        });
    }

    /**
     * Registra una devolución en el inventario
     *
     * @param Model $model El modelo al que se aplica la devolución
     * @param float $quantity La cantidad devuelta
     * @param string $description Descripción de la devolución
     * @param int|null $referenceId ID de referencia opcional
     * @param class-string|null $referenceType Clase del modelo al que pertenece $referenceId
     * @return ManagementInventory El registro creado
     */
    public function registerReturn(
        Model $model,
        float $quantity,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): ManagementInventory {
        return DB::transaction(function () use ($model, $quantity, $description, $referenceId, $referenceType) {
            DB::table($model->getTable())
                ->where('id', $model->id)
                ->increment('stock', $quantity);

            return $this->registerMovement(
                $model,
                $quantity,
                TypeInventoryManagementEnum::DEVOLUCION,
                $description,
                $referenceId,
                $referenceType
            );
        });
    }

}
