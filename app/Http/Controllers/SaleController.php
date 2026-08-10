<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleDetailResource;
use App\Http\Resources\SaleResource;
use App\Models\DailySalesReconciliation;
use App\Services\ClientVisitService;
use Illuminate\Http\Request;
use App\Models\ProductPrice;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\AccountReceivableService;
use App\Services\AssignedProductMovementService;
use App\Services\ManagementInventoryService;
use App\Services\SaleService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SaleController extends Controller
{
    use ApiResponse;

    private $saleService;

    public function __construct()
    {
        $this->saleService = new SaleService(
            new ManagementInventoryService(),
            new AccountReceivableService(),
            new ClientVisitService(),
            new AssignedProductMovementService()
        );
    }

    private function validateExistingReconciliation(int $employeeId): void
    {
        $today = Carbon::now()->toDateString();

        $existingReconciliation = DailySalesReconciliation::where('employee_id', $employeeId)
            ->whereDate('reconciliation_date', $today)
            ->exists();

        if ($existingReconciliation) {
            throw new Exception("No se puede crear una venta porque ya existe un cuadre para el día de hoy.", 422);
        }
    }

    /**
     * Create a new sale.
     * @param SaleRequest $request
     */
    public function createSale(SaleRequest $request): JsonResponse
    {
        try {
            $employeeId = Auth::user()->employee_id;

            // La verificación de idempotencia va antes que cualquier otra validación:
            // un reintento de una venta ya registrada debe responder éxito aunque
            // luego se haya creado el cuadre del día
            if ($uuid = $request->input('client_request_uuid')) {
                $existingSale = Sale::where('client_request_uuid', $uuid)->first();
                if ($existingSale) {
                    return $this->successResponse(
                        $existingSale->id,
                        "Venta #INV-{$existingSale->id} ya registrada"
                    );
                }
            }

            $this->validateExistingReconciliation($employeeId);

            $saleData = $this->prepareSaleData(collect($request->validated())->except('products')->toArray());
            $productsSaleData = $this->prepareSaleDetailsData($request['products']);
            $sale = $this->saleService->createSale($saleData, $productsSaleData);

            return $this->successResponse($sale->id, "Venta #INV-{$sale->id} creada con éxito");

        } catch (\Illuminate\Database\QueryException $qe) {
            if (isset($qe->errorInfo[1]) && $qe->errorInfo[1] === 1062) {
                $uuid = $request->input('client_request_uuid');
                if ($uuid) {
                    $existingSale = Sale::where('client_request_uuid', $uuid)->first();
                    if ($existingSale) {
                        return $this->successResponse(
                            $existingSale->id,
                            "Venta #INV-{$existingSale->id} ya registrada"
                        );
                    }
                }
                return $this->errorResponse(
                    new Exception("Conflicto de UUID"),
                    409,
                    "Conflicto: la venta con este UUID ya fue procesada"
                );
            }
            return $this->errorResponse($qe, 500, "Error al crear la venta");

        } catch (Exception $e) {
            return $this->errorResponse(
                $e,
                $e->getCode() ?: 500,
                "Error al crear la venta"
            );
        }
    }

    /**
     * Get all sales.
     * @return JsonResponse
     */
    public function getSales(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date');

            $sales = Sale::toDay($date)
                ->where('employee_id', Auth::user()->employee_id)
                ->with([
                    'client:id,first_name,last_name,business_name',
                    'employee:id,first_name,last_name'
                ])
                ->orderByDesc('created_at')
                ->get();

            return $this->successResponse(SaleResource::collection($sales), "Ventas obtenidas con éxito");
        } catch (Exception $exc) {
            return $this->errorResponse(
                $exc,
                500,
                "Error al obtener las ventas"
            );
        }
    }

    /**
     * Get sale details by sale ID.
     * @param int $id
     * @return JsonResponse
     */
    public function getSaleDetailsBySaleId(int $id): JsonResponse
    {
        try {
            $sale = Sale::findOrFail($id);
            return $this->successResponse([
                'sale' => new SaleResource($sale),
                'details' => SaleDetailResource::collection($sale->details)
            ], "Detalles de la venta obtenidos con éxito");
        } catch (Exception $exc) {
            return $this->errorResponse(
                $exc,
                $exc->getCode() ?: 500,
                "Ocurrió un error al obtener el detalle de la venta"
            );
        }
    }

    private function prepareSaleData(array $data): array
    {
        return [
            'client_id' => $data['client_id'],
            'employee_id' => $data['employee_id'],
            'sale_date' => Carbon::now(),
            'cash_amount' => $data['cash_amount'],
            'payment_reference' => $data['payment_reference'],
            'notes' => $data['notes'],
            'payment_method' => $data['payment_method'],
            'payment_term' => $data['payment_term'],
            'branch_id' => Auth::user()->employee->branch_id,
            'client_request_uuid' => $data['client_request_uuid'] ?? null,
        ];
    }

    /**
     * Create sale detail data from product data.
     * @param array $productData
     * @return array
     */
    private function prepareSaleDetailsData(array $productData): array
    {
        $details = [];
        foreach ($productData as $product) {
            $productPrice = ProductPrice::with([
                'taxCategory:id,name,rate',
                'productUnit:id,product_id,unit_id',
                'productUnit.unit:id,name,abbreviation',
                'product:id,name,code'
            ])->find($product['product_price_id']);

            if (!$productPrice)
                continue; // Skip if product price not found

            $lineSubtotal = $productPrice->getPriceWithoutTax() * (int) $product['quantity'];
            $lineTaxAmount = $productPrice->getTaxAmount() * (int) $product['quantity'];
            $lineTotal = $lineSubtotal + $lineTaxAmount;

            $details[] = [
                'origin' => 'api',
                'product_id' => $product['product_id'],
                'product_price_id' => $productPrice->id,
                'name' => $productPrice->product->name,
                'code' => $productPrice->product->code,
                'type_price_id' => $productPrice->type_price_id,
                'unit_name' => $productPrice->productUnit->unit->name,
                'unit_abbreviation' => $productPrice->productUnit->unit->abbreviation,
                'product_unit_id' => $productPrice->product_unit_id,
                'quantity' => (int) $product['quantity'],
                'base_quantity' => (int) $product['quantity'],
                'unit_price_without_tax' => $productPrice->getPriceWithoutTax(),
                'unit_price_with_tax' => $productPrice->getPriceWithTax(),
                'unit_tax_amount' => $productPrice->getTaxAmount(),
                'tax_category_id' => $productPrice->tax_category_id,
                'tax_category_name' => $productPrice->taxCategory->name,
                'tax_rate' => $productPrice->taxCategory->rate ?? 0,
                'price_include_tax' => $productPrice->price_include_tax,
                'line_subtotal' => $lineSubtotal,
                'line_tax_amount' => $lineTaxAmount,
                'line_total' => $lineTotal,
                'discount_percentage' => $product['discount_percentage'] ?? 0,
            'discount_amount' => ($lineTotal * ($product['discount_percentage'] ?? 0)) / 100,
            'movement_type' => $product['movement_type'] ?? null,
        ];
        }
        return $details;
    }
}
