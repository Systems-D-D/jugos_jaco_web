<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignedProductResource;
use App\Models\AssignedProduct;
use App\Services\ProductService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AssignedProductController extends Controller
{
    use ApiResponse;

    private $productService;

    public function __construct()
    {
        $this->productService = new ProductService();
    }

    /**
     * Obtener los productos asignados al empleado asociado al usuario autenticado para la fecha actual.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductAssigned(Request $request)
    {
        try {
            $assignedProducts = $this->productService->getAssignedProduct(Auth::user()->employee_id);

            if (!$assignedProducts) {
                throw new \Exception('No hay productos asignados para el empleado en la fecha actual.');
            }

            $details = $assignedProducts->details;

            return $this->successResponse(
                AssignedProductResource::collection($details),
                'Productos asignados obtenidos correctamente.'
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }
}
