<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssignedProductMovementTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssignedProductMovementResource;
use App\Models\AssignedProduct;
use App\Models\AssignedProductMovement;
use App\Services\AssignedProductMovementService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
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
        try {
            $request->validate([
                'detail_assigned_product_id' => 'required|exists:detail_assigned_products,id',
                'type' => ['required', Rule::in(array_column(AssignedProductMovementTypeEnum::cases(), 'value'))],
                'quantity' => 'required|numeric|min:0.01',
                'note' => 'nullable|string|max:255',
            ]);

            $movement = $this->movementService->createMovement(
                $request->detail_assigned_product_id,
                $request->type,
                $request->quantity,
                $request->note
            );

            return $this->successResponse($movement, 'Movimiento registrado correctamente.', 201);

        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, $e->getMessage());
        }
    }

    /**
     * Delete a movement.
     */
    public function deleteMovement($id)
    {
        try {
            $this->movementService->deleteMovement($id);
            return $this->successResponse(null, 'Movimiento eliminado correctamente.');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Error al eliminar el movimiento.');
        }
    }
}
