<?php

namespace App\Livewire\Sales;

use App\Enums\SaleStatusEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\PaymentTypeEnum;
use App\Models\Client;
use App\Models\Employee;
use App\Models\FinishedProductInventory;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\ProductService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class CreateSale extends Component
{
    // Search functionality
    public array $search_results = [];
    public bool $show_search_results = false;
    public ?string $product_search = null;

    // Products in sale
    public array $products = [];

    // Form data properties
    public ?string $client_id = null;
    public ?string $employee_id = null;
    public ?string $sale_date = null;
    public ?string $notes = null;

    // Payment properties
    public bool $showPaymentModal = false;
    public ?string $payment_method = null;
    public ?string $payment_term = null;
    public $amount_paid = 0.0;
    public ?string $payment_reference = null;
    
    // Computed properties for view compatibility
    public string $payment_type = 'cash';
    public float $cash_amount = 0.0;

    // Totals
    public float $subtotal = 0.0;
    public float $final_total = 0.0;
    public array $tax_totals = [];

    // Modal states
    public bool $showConfirmationModal = false;
    public ?Sale $createdSale = null;

    // Collections for dropdowns
    public $clients;
    public $employees;

    protected $cast = [
        'amount_paid' => 'decimal:2',
        'final_total' => 'decimal:2',
    ];

    public function mount(): void
    {
        $this->clients = Client::orderBy('first_name')->whereHas('typePrice')->get();
        $this->employees = Employee::orderBy('first_name')->get();
        $this->amount_paid = 0.0;

        // Initialize default values
        $user = Auth::user();
        if ($user && $user->employee) {
            $this->employee_id = $user->employee->id;
        }

        $this->sale_date = now()->format('Y-m-d');
        
        // Initialize payment properties
        $this->payment_type = 'cash';
        $this->payment_term = PaymentTermEnum::CASH->value;
        $this->cash_amount = 0.0;
    }

    /**
     * Updated when payment_method changes
     */
    public function updatedPaymentMethod()
    {
        $payment_type_mapping = [
            'efectivo' => 'cash',
            'tarjeta' => 'card',
            'transferencia' => 'deposit',
            'credito' => 'credit',
        ];
        
        $this->payment_type = $payment_type_mapping[$this->payment_method] ?? 'cash';
        
        // Automatically set payment_term based on payment_method
        if ($this->payment_method === 'credito') {
            $this->payment_term = PaymentTermEnum::CREDIT->value;
        } else {
            $this->payment_term = PaymentTermEnum::CASH->value;
        }
    }

    /**
     * Updated when amount_paid changes
     */
    public function updatedAmountPaid()
    {
        $this->cash_amount = (float)$this->amount_paid;
    }

    /**
     * Updated when product_search changes
     */
    public function updatedProductSearch()
    {
        // Solo limpiar resultados si está vacío
        if (empty($this->product_search) || strlen(trim($this->product_search)) < 2) {
            $this->search_results = [];
            $this->show_search_results = false;
        }
        // No hacer búsqueda automática, solo al presionar Enter
    }

    /**
     * Handle Enter key press in search field - buscar y agregar directamente
     */
    public function addFirstProduct(): void
    {
        if (empty($this->product_search) || strlen(trim($this->product_search)) < 2) {
            session()->flash('error', 'El campo de busqueda esta vacio.');
            return;
        }

        if (empty($this->client_id)){
            session()->flash('error','Seleccione un cliente.');
            return;
        } 
            
        // Realizar búsqueda directamente
        $productService = app(ProductService::class);
        $branchId = Auth::user()->employee->branch_id ?? 1;
        $typePriceId = $this->client_id ? 
            Client::find($this->client_id)?->type_price_id : 
            null;

        $products = $productService->getSearchProduct(trim($this->product_search), $typePriceId, $branchId);

        // Si no hay productos, mostrar mensaje
        if ($products->isEmpty()) {
            session()->flash('error', 'No se encontraron productos con ese código o nombre.');
            return;
        }

        // Tomar el primer producto encontrado
        $item = $products->first();
        $product = $this->transformProduct($item);

        // Agregar el producto directamente
        $this->addProductToSale($product);
    }

    /**
     * Transform product data for internal use
     */
    private function transformProduct($item): array
    {
        $productPrice = $item->product->productPrices->first();
        $taxCategory = $productPrice?->taxCategory;
        
        if (!$productPrice) {
            // Si no hay precio configurado, usar valores por defecto
            return [
                'id' => $item->id,
                'product_id' => $item->product->id,
                'name' => $item->product->name,
                'code' => $item->product->code,
                'stock' => $item->stock,
                'price' => 0,
                'price_without_tax' => 0,
                'price_with_tax' => 0,
                'price_include_tax' => false,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'tax_category_name' => 'Sin categoría',
                'tax_category_id' => null,
                'type_price_id' => null,
                'unit_abbreviation' => $item->product->productUnits->first()?->unit?->abbreviation ?? '',
                'unit_name' => $item->product->productUnits->first()?->unit?->name ?? '',
                'product_unit_id' => $item->product->productUnits->first()?->id ?? null,
                'conversion_factor' => $item->product->productUnits->first()?->conversion_factor ?? 1,

            ];
        }
        
        // IMPORTANTE: Usar los métodos del modelo ProductPrice para cálculos precisos
        $price = $productPrice->price;
        $price_include_tax = $productPrice->price_include_tax;
        $tax_rate = $taxCategory?->rate ?? 0;
        
        // Usar métodos del modelo para cálculos exactos
        $price_without_tax = $productPrice->getPriceWithoutTax();
        $price_with_tax = $productPrice->getPriceWithTax();
        $tax_amount = $productPrice->getTaxAmount();   
                        
        return [
            'id' => $item->id,
            'product_id' => $item->product->id,
            'name' => $item->product->name,
            'code' => $item->product->code,
            'stock' => $item->stock,
            'price' => $price, // Precio original del sistema (como está configurado)
            'price_without_tax' => $price_without_tax, // Precio base sin impuesto (calculado por modelo)
            'price_with_tax' => $price_with_tax, // Precio final con impuesto (calculado por modelo)
            'price_include_tax' => $price_include_tax, // Indica si el precio original incluye impuesto
            'tax_amount' => $tax_amount, // Monto del impuesto (calculado por modelo)
            'tax_rate' => $tax_rate,
            'tax_category_name' => $taxCategory?->name ?? 'Sin categoría',
            'tax_category_id' => $taxCategory?->id ?? null,
            'type_price_id' => $productPrice->type_price_id, // ID del tipo de precio
            'unit_abbreviation' => $item->product->productUnits->first()?->unit?->abbreviation ?? '',
            'unit_name' => $item->product->productUnits->first()?->unit?->name ?? '',
            'product_unit_id' => $item->product->productUnits->first()?->id ?? null, // ID de la unidad del producto
            'conversion_factor' => $item->product->productUnits->first()?->conversion_factor ?? 1, // Factor de conversión de la unidad
        ];
    }

    /**
     * Add product to sale - refactored method
     */
    private function addProductToSale(array $product): void
    {
        // Check if product already exists
        $existingIndex = null;
        foreach ($this->products as $index => $item) {
            if ($item['product_id'] === $product['product_id']) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex !== null) {
            // Increase quantity
            $currentQuantity = $this->products[$existingIndex]['quantity'];
            $maxStock = $this->products[$existingIndex]['stock'];

            if ($currentQuantity < $maxStock) {
                $this->products[$existingIndex]['quantity'] += 1;
                $this->updateProductSubtotal($existingIndex);
            } else {
                session()->flash('error', 'No hay suficiente stock disponible para ' . $product['name']);
            }
        } else {
            // Add new product
            $basePrice = $product['price_without_tax'];
            $taxAmount = $product['tax_amount'];
            $productUnitId = $product['product_unit_id'];
            $conversion_factor = $product['conversion_factor']; 

            $stock = $product['stock'] / $conversion_factor;
            $quantity = min(1, $stock);
            $baseQuantity = $quantity * $conversion_factor;

            $this->products[] = [
                'inventory_id' => $product['id'],
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'code' => $product['code'],
                'type_price_id' => $product['type_price_id'],
                'unit_price_without_tax' => $basePrice,
                'unit_tax_amount' => $taxAmount,
                'price_include_tax' => $product['price_include_tax'],
                'price_with_tax' => $product['price_with_tax'],
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'line_subtotal' => $basePrice * $quantity,
                'line_tax_amount' => $taxAmount * $quantity,
                'line_total' => ($basePrice + $taxAmount) * $quantity,
                'tax_rate' => $product['tax_rate'],
                'tax_category_name' => $product['tax_category_name'],
                'tax_category_id' => $product['tax_category_id'],
                'unit_abbreviation' => $product['unit_abbreviation'],
                'unit_name' => $product['unit_name'],
                'stock' => $stock,
                'product_unit_id' => $productUnitId,
                'discount_percentage' => 0,
                'discount_amount' => 0,
            ];
        }

        // Limpiar búsqueda y actualizar totales
        $this->clearSearch();
        $this->updateSaleTotals();
        
        // Mostrar mensaje de éxito
        if ($existingIndex !== null) {
            session()->flash('success', 'Cantidad aumentada para: ' . $product['name']);
        } else {
            session()->flash('success', 'Producto agregado: ' . $product['name']);
        }
    }

    /**
     * Update product subtotal and totals when quantity changes
     */
    private function updateProductSubtotal($index): void
    {
        if (isset($this->products[$index])) {
            $quantity = $this->products[$index]['quantity'];
            $unitPriceWithoutTax = $this->products[$index]['unit_price_without_tax'];
            $unitTaxAmount = $this->products[$index]['unit_tax_amount'];
            
            // Calcular totales de línea
            $this->products[$index]['line_subtotal'] = $quantity * $unitPriceWithoutTax;
            $this->products[$index]['line_tax_amount'] = $quantity * $unitTaxAmount;
            $this->products[$index]['line_total'] = $this->products[$index]['line_subtotal'] + $this->products[$index]['line_tax_amount'];
        }
    }

    /**
     * Clear search results and input
     */
    public function clearSearch(): void
    {
        $this->search_results = [];
        $this->show_search_results = false;
        $this->product_search = '';
    }

    /**
     * Remove a product from the sale
     */
    public function removeProduct(int $index): void
    {
        if (isset($this->products[$index])) {
            $productName = $this->products[$index]['name'];
            unset($this->products[$index]);
            $this->products = array_values($this->products); // Re-index array
            $this->updateSaleTotals();
            session()->flash('success', 'Producto removido: ' . $productName);
        }
    }

    /**
     * Update product quantity in sale
     */
    public function updateQuantity(int $index, float $quantity): void
    {
        if (isset($this->products[$index]) && $quantity > 0) {
            $productUnitId = $this->products[$index]['product_unit_id'];
            $productUnit = ProductUnit::find($productUnitId);
            $maxStock = $this->products[$index]['stock'];
            $finalQuantity = min($quantity, $maxStock);
            $baseQuantity = $quantity * $productUnit->conversion_factor;

            $this->products[$index]['quantity'] = $finalQuantity;
            $this->products[$index]['base_quantity'] = $baseQuantity;
            $this->updateProductSubtotal($index);
            $this->updateSaleTotals();
        }
    }

    /**
     * Update sale totals
     */
    public function updateSaleTotals(): void
    {
        $subtotal = 0;
        $tax_totals = [];

        foreach ($this->products as $item) {
            $itemSubtotal = $item['line_subtotal'] ?? 0;
            $itemTaxAmount = $item['line_tax_amount'] ?? 0;
            $itemTaxRate = $item['tax_rate'] ?? 0;
            $itemTaxCategoryName = $item['tax_category_name'] ?? 'Sin categoría';
            
            $subtotal += $itemSubtotal;
            
            if ($itemTaxRate > 0 && $itemTaxAmount > 0) {
                $found = false;
                foreach ($tax_totals as &$taxGroup) {
                    if ($taxGroup['tax_rate'] == $itemTaxRate && $taxGroup['tax_category_name'] == $itemTaxCategoryName) {
                        $taxGroup['tax_amount'] += $itemTaxAmount;
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $tax_totals[] = [
                        'tax_rate' => $itemTaxRate,
                        'tax_category_name' => $itemTaxCategoryName,
                        'tax_amount' => $itemTaxAmount,
                    ];
                }
            }
        }

        $totalTax = array_sum(array_column($tax_totals, 'tax_amount'));
        $final_total = $subtotal + $totalTax;

        $this->subtotal = $subtotal;
        $this->final_total = $final_total;
        $this->tax_totals = $tax_totals;
    }

    /**
     * Open payment modal
     */
    public function openPaymentModal(): void
    {
        if (empty($this->products)) {
            session()->flash('error', 'Debe agregar al menos un producto a la venta.');
            return;
        }

        $this->showPaymentModal = true;
    }

    /**
     * Close payment modal
     */
    public function closePaymentModal(): void
    {
        $this->showPaymentModal = false;
        $this->payment_method = null;
        $this->payment_term = PaymentTermEnum::CASH->value;
        $this->amount_paid = 0.0;
        $this->payment_reference = null;
        
        // Reset computed properties
        $this->payment_type = 'cash';
        $this->cash_amount = 0.0;
    }

    /**
     * Save the sale
     */
    public function save(): void
    {
        // Validation
        // sale_date no se valida como entrada editable: la fecha de venta la fija
        // el servidor (ver R7 en docs/devflow/specs/2026-08-10-sale-deletion-analysis.md).
        // Un campo readonly en el HTML no impide que un cliente Livewire manipulado
        // envíe otro valor, así que se ignora explícitamente más abajo.
        $this->validate([
            'employee_id' => 'required|exists:employees,id',
            'payment_method' => 'required|string',
            'payment_term' => 'required|string|in:' . implode(',', array_column(PaymentTermEnum::cases(), 'value')),
            'amount_paid' => 'required|numeric|min:0',
        ]);

        if (empty($this->products)) {
            session()->flash('error', 'Debe agregar al menos un producto a la venta.');
            return;
        }

        try {
            DB::beginTransaction();
            $managementInventoryService = app(\App\Services\ManagementInventoryService::class);
            $accountReceivableService = app(\App\Services\AccountReceivableService::class);

            $subtotal = $this->subtotal;
            $final_total = $this->final_total;
            
            $payment_type_mapping = [
                'efectivo' => 'cash',
                'tarjeta' => 'card',
                'transferencia' => 'deposit',
                'credito' => 'credit',
            ];
            
            $payment_type = $payment_type_mapping[$this->payment_method] ?? 'cash';

            $sale = Sale::create([
                'client_id' => $this->client_id,
                'employee_id' => $this->employee_id,
                'sale_date' => now(),
                'subtotal' => $subtotal,
                'total_amount' => $final_total,
                'payment_method' => PaymentTypeEnum::from($payment_type),
                'payment_term' => PaymentTermEnum::from($this->payment_term),
                'cash_amount' => $this->amount_paid,
                'payment_reference' => $this->payment_reference,
                'notes' => $this->notes,
                'status' => $this->amount_paid >= $final_total ? SaleStatusEnum::PAID : SaleStatusEnum::PARTIALLY_PAID,
                'discount_percentage' => 0,
                'discount_amount' => 0, 
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            foreach ($this->products as $item) {
                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['name'],
                    'product_code' => $item['code'] ?? null,
                    'type_price_id' => $item['type_price_id'],
                    'unit_name' => $item['unit_name'],
                    'unit_abbreviation' => $item['unit_abbreviation'],
                    'quantity' => $item['quantity'],
                    'base_quantity' => $item['base_quantity'],
                    'unit_price_without_tax' => $item['unit_price_without_tax'],
                    'unit_tax_amount' => $item['unit_tax_amount'],
                    'tax_category_id' => $item['tax_category_id'],
                    'tax_category_name' => $item['tax_category_name'],
                    'line_subtotal' => $item['line_subtotal'],
                    'line_tax_amount' => $item['line_tax_amount'],
                    'line_total' => $item['line_total'],
                    'discount_percentage' => $item['discount_percentage'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                ]);

                $inventoryModel = FinishedProductInventory::find($item['inventory_id']);
                if ($inventoryModel) {
                    $managementInventoryService->processMovement(
                        $inventoryModel,
                        $item['base_quantity'],
                        \App\Enums\TypeInventoryManagementEnum::SALIDA->value,
                        'Venta de producto: ' . $item['name'],
                        $sale->id,
                    );
                }
            }

            // Crear cuenta por cobrar si la venta es de término crédito
            if ($this->payment_term === PaymentTermEnum::CREDIT->value) {
                $accountReceivableService->create(
                    sale: $sale,
                    totalAmount: null,
                    name: null,
                    notes: $this->notes,
                    dueDate: Carbon::now()->addDays(7),
                    amountPaidNow: (float) $this->amount_paid,
                );
            }

            DB::commit();

            // Close payment modal and show confirmation
            $this->closePaymentModal();
            $this->createdSale = $sale->load(['client', 'employee']);
            $this->showConfirmationModal = true;
            
            session()->flash('success', 'Venta creada exitosamente.');
            
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Error al crear la venta: ' . $e->getMessage());
        }
    }

    /**
     * Close confirmation modal and redirect
     */
    public function closeConfirmationModal(): void
    {
        $this->showConfirmationModal = false;
        $this->createdSale = null;
        
        $this->redirect(\App\Filament\Resources\SaleResource::getUrl('index'));
    }

    /**
     * Create new sale - reset form
     */
    public function createNewSale(): void
    {
        $this->showConfirmationModal = false;
        $this->createdSale = null;
        
        // Reset form
        $this->reset([
            'products', 
            'search_results', 
            'client_id', 
            'notes',
            'payment_method',
            'payment_term',
            'amount_paid',
            'payment_reference'
        ]);
        
        $this->show_search_results = false;
        $this->sale_date = now()->format('Y-m-d');
        
        // Reset computed properties
        $this->payment_type = 'cash';
        $this->payment_term = PaymentTermEnum::CASH->value;
        $this->cash_amount = 0.0;
        
        $this->updateSaleTotals();
    }

    /**
     * Get payment term options for the view
     */
    public function getPaymentTermOptionsProperty(): array
    {
        return PaymentTermEnum::getOptions();
    }

    public function render()
    {
        return view('livewire.sales.create-sale');
    }
}
