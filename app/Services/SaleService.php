<?php

namespace App\Services;

use App\Enums\SaleStatusEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\PaymentTermEnum;
use App\Exceptions\InsufficientAssignedStockException;
use App\Models\AssignedProduct;
use App\Models\DetailAssignedProduct;
use App\Models\FinishedProductInventory;
use App\Models\Sale;
use App\Models\SaleDetail;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaleService
{
    protected $managementInventoryService;
    protected $accountReceivableService;
    protected $clientVisitService;
    protected $assignedProductMovementService;

    /**
     * Constructor del servicio.
     *
     * @param ManagementInventoryService $managementInventoryService
     * @param AccountReceivableService $accountReceivableService
     * @param ClientVisitService $clientVisitService
     * @param AssignedProductMovementService $assignedProductMovementService
     */
    public function __construct(
        ManagementInventoryService $managementInventoryService,
        AccountReceivableService $accountReceivableService,
        ClientVisitService $clientVisitService,
        AssignedProductMovementService $assignedProductMovementService
    ) {
        $this->managementInventoryService = $managementInventoryService;
        $this->accountReceivableService = $accountReceivableService;
        $this->clientVisitService = $clientVisitService;
        $this->assignedProductMovementService = $assignedProductMovementService;
    }

    /**
     * Crea una nueva venta con sus detalles asociados.
     * 
     * @param array $saleData Datos de la cabecera de la venta
     * @param array $productsData Datos de los productos/detalles de la venta
     * @return Sale
     * @throws Exception
     */
    public function createSale(array $saleData, array $productsData): Sale
    {
        try {
            DB::beginTransaction();

            // 1. Calcular totales
            $calculatedTotals = $this->calculateTotals($productsData);
            $subtotal = $calculatedTotals['subtotal'];
            $finalTotal = $calculatedTotals['final_total'];

            // 2. Verificar el tipo de pago basado en el monto pagado vs total
            $cashAmount = $saleData['cash_amount'] ?? 0;
            $paymentMethod = $saleData['payment_method'] ?? PaymentTypeEnum::CASH->value;
            $paymentTerm = $saleData['payment_term'] ?? PaymentTermEnum::CASH->value;

            // Si el monto pagado es menor al total, se considera venta a crédito
            if ($cashAmount < $finalTotal && $paymentTerm !== PaymentTermEnum::CREDIT->value) {
                $paymentTerm = PaymentTermEnum::CREDIT->value;
            }

            // 3. Determinar el estado de la venta
            $status = SaleStatusEnum::CONFIRMED;
            if ($paymentTerm === PaymentTermEnum::CREDIT->value) {
                $status = $cashAmount <= 0 ? SaleStatusEnum::CONFIRMED : SaleStatusEnum::PARTIALLY_PAID;
            } else if ($cashAmount >= $finalTotal) {
                $status = SaleStatusEnum::PAID;
            }

            // 4. Crear la venta
            $sale = Sale::create([
                'client_id' => $saleData['client_id'],
                'employee_id' => $saleData['employee_id'],
                'sale_date' => $saleData['sale_date'],
                'subtotal' => $subtotal,
                'total_amount' => $finalTotal,
                'payment_term' => $paymentTerm,
                'payment_method' => $paymentMethod,
                'cash_amount' => $cashAmount,
                'payment_reference' => $saleData['payment_reference'] ?? null,
                'notes' => $saleData['notes'] ?? null,
                'status' => $status,
                'branch_id' => $saleData['branch_id'],
                'due_date' => ($paymentTerm === PaymentTermEnum::CREDIT->value) ?
                    ($saleData['due_date'] ?? Carbon::now()->addDays(7)) : null,
                'client_request_uuid' => $saleData['client_request_uuid'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // 5. Crear los detalles de la venta
            $this->createSaleDetails($sale, $productsData);

            // 6. Crear cuenta por cobrar si es venta a crédito
            if ($paymentTerm === PaymentTermEnum::CREDIT->value) {
                $this->accountReceivableService->create(
                    sale: $sale,
                    totalAmount: null,
                    name: null,
                    notes: $saleData['notes'] ?? null,
                    dueDate: $sale->due_date,
                    amountPaidNow: (float) $cashAmount,
                );
            }

            // 7. Registrar la visita del día para el cliente
            $this->clientVisitService->registerVisit(
                clientId: $sale->client_id,
                userId: Auth::id(),
                visitDate: $sale->sale_date,
                visited: true
            );

            DB::commit();
            return $sale->fresh(['client', 'employee', 'details']);

        } catch (\Illuminate\Database\QueryException $qe) {
            DB::rollBack();
            Log::error('Error DB en SaleService::createSale: ' . $qe->getMessage());
            throw $qe;

        } catch (InsufficientAssignedStockException $e) {
            // Se propaga sin envolver para que el controlador la traduzca a 422:
            // reintentar no la resuelve.
            DB::rollBack();
            Log::warning('Stock insuficiente en SaleService::createSale: ' . $e->getMessage());
            throw $e;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en SaleService::createSale: ' . $e->getMessage());
            throw new Exception('Error al crear la venta: ' . $e->getMessage(), $e->getCode());
        }
    }

    /**
     * Crea los detalles de la venta y actualiza el inventario.
     *
     * @param Sale $sale
     * @param array $productsData
     * @return void
     */
    protected function createSaleDetails(Sale $sale, array $productsData): void
    {
        // El resultado no debe depender del orden en que el cliente arme el payload:
        // las líneas de venta se procesan siempre primero y los movimientos
        // (regalías/cambios) después, de modo que el control de stock de cada
        // movimiento ya vea descontado lo vendido en este mismo ticket.
        $saleLines = [];
        $movementLines = [];

        foreach ($productsData as $productData) {
            if (!empty($productData['movement_type'])) {
                $movementLines[] = $productData;
            } else {
                $saleLines[] = $productData;
            }
        }

        foreach ($saleLines as $productData) {
            if (isset($productData['origin']) && $productData['origin'] === 'api') {
                $productosAsignados = AssignedProduct::where('employee_id', Auth::user()->employee->id)
                    ->todayAssignments()
                    ->first();

                if ($productosAsignados) {
                    $detail = DetailAssignedProduct::where('assigned_products_id', $productosAsignados->id)
                        ->where('product_id', $productData['product_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($detail) {
                        // El sobrante disponible descuenta también regalías, cambios y
                        // devoluciones: vender contra `quantity` permitía vender unidades
                        // que ya habían salido como movimiento y dejaba el stock negativo.
                        $available = (float) $detail->stock;

                        if ($available < $productData['quantity']) {
                            throw new InsufficientAssignedStockException(
                                "La cantidad a vender del producto {$productData['name']} excede el sobrante disponible ({$available})"
                            );
                        }

                        $detail->update([
                            'sale_quantity' => ($detail->sale_quantity ?? 0) + $productData['quantity'],
                        ]);
                    }
                }
            }
            // 1. Crear detalle de venta
            SaleDetail::create([
                'sale_id' => $sale->id,
                'product_id' => $productData['product_id'],
                'product_name' => $productData['name'] ?? null,
                'product_code' => $productData['code'] ?? null,
                'product_price_id' => $productData['product_price_id'] ?? null,
                'type_price_id' => $productData['type_price_id'] ?? null,
                'unit_name' => $productData['unit_name'] ?? null,
                'unit_abbreviation' => $productData['unit_abbreviation'] ?? null,
                'product_unit_id' => $productData['product_unit_id'] ?? null,
                'conversion_factor' => $productData['conversion_factor'] ?? 1,
                'quantity' => $productData['quantity'],
                'base_quantity' => $productData['base_quantity'] ?? $productData['quantity'],
                'unit_price_without_tax' => $productData['unit_price_without_tax'],
                'unit_tax_amount' => $productData['unit_tax_amount'] ?? 0,
                'tax_category_id' => $productData['tax_category_id'] ?? null,
                'tax_category_name' => $productData['tax_category_name'] ?? null,
                'tax_rate' => $productData['tax_rate'] ?? 0,
                'price_include_tax' => $productData['price_include_tax'] ?? false,
                'line_subtotal' => $productData['line_subtotal'] ?? ($productData['quantity'] * $productData['unit_price_without_tax']),
                'line_tax_amount' => $productData['line_tax_amount'] ?? ($productData['quantity'] * ($productData['unit_tax_amount'] ?? 0)),
                'line_total' => $productData['line_total'] ??
                    ($productData['quantity'] * $productData['unit_price_without_tax']) +
                    ($productData['quantity'] * ($productData['unit_tax_amount'] ?? 0)),
                'discount_percentage' => $productData['discount_percentage'] ?? 0,
                'discount_amount' => $productData['discount_amount'] ?? 0,
            ]);

            // 2. Actualizar inventario si hay ID de inventario - solo vendra de venta creada en el sitio web
            if (isset($productData['inventory_id'])) {
                $inventoryModel = FinishedProductInventory::find($productData['inventory_id']);
                if ($inventoryModel) {
                    $baseQuantity = $productData['base_quantity'] ?? $productData['quantity'];

                    $this->managementInventoryService->processMovement(
                        $inventoryModel,
                        $baseQuantity,
                        TypeInventoryManagementEnum::SALIDA->value,
                        'Venta de producto: ' . ($productData['name'] ?? 'Producto #' . $productData['product_id']),
                        $sale->id,
                        Sale::class,
                    );
                }
            }
        }

        // --- CASE: Royalty or Change → AssignedProductMovement ---
        foreach ($movementLines as $productData) {
            $this->createMovementFromSaleProduct($sale, $productData);
        }
    }

    /**
     * Calcula los totales para la venta.
     *
     * @param array $products
     * @return array
     */
    public function calculateTotals(array $products): array
    {
        $subtotal = 0;
        $totalTaxes = 0;

        foreach ($products as $product) {
            // Skip products with movement_type (royalty or change)
            if (!empty($product['movement_type'])) {
                continue;
            }

            // Calcular subtotal de línea
            $lineSubtotal = $product['line_subtotal'] ??
                ($product['quantity'] * $product['unit_price_without_tax']);

            // Calcular impuesto de línea
            $lineTaxAmount = $product['line_tax_amount'] ??
                ($product['quantity'] * ($product['unit_tax_amount'] ?? 0));

            // Acumular totales
            $subtotal += $lineSubtotal;
            $totalTaxes += $lineTaxAmount;
        }

        return [
            'subtotal' => $subtotal,
            'total_taxes' => $totalTaxes,
            'final_total' => $subtotal + $totalTaxes,
        ];
    }

    /**
     * Crea un AssignedProductMovement vinculado a la venta para productos
     * que son regalías (royalty) o cambios (change).
     */
    private function createMovementFromSaleProduct(Sale $sale, array $productData): void
    {
        $assignedProduct = AssignedProduct::where('employee_id', $sale->employee_id)
            ->whereDate('date', $sale->sale_date)
            ->first();

        if (!$assignedProduct) {
            throw new Exception(
                "No hay asignación de productos para el empleado en la fecha de la venta."
            );
        }

        $detail = DetailAssignedProduct::where('assigned_products_id', $assignedProduct->id)
            ->where('product_id', $productData['product_id'])
            ->first();

        if (!$detail) {
            throw new Exception(
                "El producto '{$productData['name']}' no está asignado al empleado para hoy."
            );
        }

        $note = $productData['movement_note'] ?? "Venta #INV-{$sale->id}";

        $this->assignedProductMovementService->createMovement(
            detailId: $detail->id,
            type: $productData['movement_type'],
            quantity: (float) $productData['quantity'],
            note: $note,
            saleId: $sale->id,
        );
    }
}
