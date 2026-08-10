<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssignedProductMovementTypeEnum;
use App\Exceptions\InsufficientAssignedStockException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssignedProductMovementResource;
use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Models\DetailAssignedProduct;
use App\Services\AssignedProductMovementService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AssignedProductMovementController extends Controller
{
    use ApiResponse;

    protected $movementService;

    public function __construct(AssignedProductMovementService $movementService)
    {
        $this->movementService = $movementService;
    }

    /**
     * Get movements for the authenticated employee and current date.
     */
    public function getMovements(Request $request)
    {
        try {
            $employeeId = Auth::user()->employee_id;

            $assignedProduct = AssignedProduct::todayAssignments()
                ->where('employee_id', $employeeId)
                ->first();

            if (!$assignedProduct) {
                return $this->successResponse([], 'No hay asignación para la fecha especificada.');
            }

            $movements = AssignedProductMovement::whereHas('detailAssignedProduct', function ($query) use ($assignedProduct) {
                    $query->where('assigned_products_id', $assignedProduct->id);
                })
                ->with(['detailAssignedProduct.product'])
                ->latest()
                ->get();

            return $this->successResponse(AssignedProductMovementResource::collection($movements), 'Movimientos obtenidos correctamente.');

        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Error al obtener los movimientos.');
        }
    }

    /**
     * Create a new movement.
     */
    public function createMovement(Request $request)
    {
        // Fuera del try: una ValidationException no debe degradarse a 500,
        // el móvil necesita el 422 con los campos inválidos.
        $request->validate([
            'detail_assigned_product_id' => 'required|exists:detail_assigned_products,id',
            'type' => ['required', Rule::in(array_column(AssignedProductMovementTypeEnum::cases(), 'value'))],
            'quantity' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
            'client_request_uuid' => 'nullable|uuid',
        ]);

        try {
            // Idempotencia: un reintento del móvil por respuesta perdida no debe
            // volver a descontar el sobrante. Se resuelve antes de cualquier otra
            // validación, igual que en SaleController::createSale.
            if ($uuid = $request->input('client_request_uuid')) {
                $existing = AssignedProductMovement::where('client_request_uuid', $uuid)->first();

                if ($existing) {
                    return $this->successResponse($existing, 'Movimiento ya registrado.');
                }
            }

            $detail = $this->resolveOwnedDetail((int) $request->detail_assigned_product_id);

            if (!$detail) {
                return $this->errorResponse(
                    new \Exception('El producto no pertenece a la asignación del empleado para hoy.'),
                    403,
                    'No autorizado para registrar movimientos sobre este producto.'
                );
            }

            $movement = $this->movementService->createMovement(
                detailId: $detail->id,
                type: $request->type,
                quantity: (float) $request->quantity,
                note: $request->note,
                saleId: null,
                clientRequestUuid: $uuid ?: null,
            );

            return $this->successResponse($movement, 'Movimiento registrado correctamente.', 201);

        } catch (InsufficientAssignedStockException $e) {
            // 422: error de negocio, reintentar no lo resuelve.
            return $this->errorResponse($e, 422, $e->getMessage());

        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Error al registrar el movimiento.');
        }
    }

    /**
     * Delete a movement.
     */
    public function deleteMovement($id)
    {
        try {
            $movement = AssignedProductMovement::find($id);

            if (!$movement) {
                return $this->errorResponse(
                    new \Exception('Movimiento no encontrado.'),
                    404,
                    'Movimiento no encontrado.'
                );
            }

            if (!$this->resolveOwnedDetail($movement->detail_assigned_product_id)) {
                return $this->errorResponse(
                    new \Exception('El movimiento no pertenece a la asignación del empleado para hoy.'),
                    403,
                    'No autorizado para eliminar este movimiento.'
                );
            }

            $this->movementService->deleteMovement($movement->id);

            return $this->successResponse(null, 'Movimiento eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Error al eliminar el movimiento.');
        }
    }

    /**
     * Devuelve el detalle sólo si pertenece a la asignación de hoy del empleado
     * autenticado. Sin esto cualquier usuario podía alterar el sobrante de otro
     * vendedor enviando un id ajeno.
     */
    private function resolveOwnedDetail(int $detailId): ?DetailAssignedProduct
    {
        $employeeId = Auth::user()->employee_id;

        if (!$employeeId) {
            return null;
        }

        return DetailAssignedProduct::whereKey($detailId)
            ->whereHas('assignedProduct', function ($query) use ($employeeId) {
                $query->where('employee_id', $employeeId)->todayAssignments();
            })
            ->first();
    }
}
