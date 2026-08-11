<?php

namespace App\Livewire\Reconciliations;

use Livewire\Component;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\Payment;
use App\Models\DailySalesReconciliation;
use App\Models\AssignedProduct;
use App\Models\DetailAssignedProduct;
use App\Models\Product;
use App\Models\Deposit;
use App\Models\Bill;
use App\Models\ProductReturn;
use App\Models\AssignedProductMovement;
use App\Services\ProductReturnService;
use App\Models\TypePrice;
use App\Models\ProductUnit;
use App\Models\ProductPrice;
use App\Enums\ReconciliationStatusEnum;
use App\Enums\BankEnum;
use App\Enums\ProductReturnTypeEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\PaymentTypeEnum;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateReconciliation extends Component
{
    // Form properties
    public ?string $employee_id = null;
    public ?string $reconciliation_date = null;

    // Sales data
    public array $sales = [];
    public array $payments = [];

    // Totals
    public float $total_cash_sales = 0.0;
    public float $total_credit_sales = 0.0;
    public float $total_sales = 0.0;
    public float $total_collections = 0.0;
    public float $total_cash_collections = 0.0;
    public float $total_deposit_collections = 0.0;
    public float $total_cash_expected = 0.0;
    public float $total_deposit_expected = 0.0;
    public float $total_deposit_sales = 0.0;
    public float $cash_received = 0.0;
    public float $cash_difference = 0.0;
    public float $deposits_made = 0.0;
    public float $deposit_difference = 0.0;

    // Deposit form properties
    public float $deposit_amount = 0.0;
    public ?string $deposit_bank = null;
    public ?string $deposit_reference = null;
    public array $deposits = [];

    // Bill form properties
    public ?string $bill_description = null;
    public float $bill_amount = 0.0;
    public ?string $bill_reference = null;
    public array $bills = [];
    public float $total_bills = 0.0;

    // Product return form properties
    public ?string $return_product_id = null;
    public ?string $return_type = null;
    public ?string $return_reason = null;
    public int $return_quantity = 1;
    public bool $return_affects_inventory = true;
    public array $returns = [];

    // Movement form properties
    public array $movements = [];

    // Remaining products properties
    public array $remaining_products = [];

    public ?string $type_price_id = null;
    public array $type_prices = [];
    public float $product_shortage_total = 0.0;

    // Product search properties (simplified)
    public string $product_search = '';

    // Collections for dropdowns
    public $employees;
    public $banks;
    public $products;
    public $return_types;

    // State
    public bool $reconciliation_created = false;
    public ?DailySalesReconciliation $current_reconciliation = null;

    protected $rules = [
        'employee_id' => 'required|exists:employees,id',
        'return_product_id' => 'required|exists:products,id',
        'return_type' => 'required|in:damaged,returned',
        'return_reason' => 'required|string|max:255',
        'return_quantity' => 'required|integer|min:1',
        'return_affects_inventory' => 'boolean',
    ];

    protected $messages = [
        'employee_id.required' => 'Debe seleccionar un empleado.',
        'employee_id.exists' => 'El empleado seleccionado no es válido.',
        'return_product_id.required' => 'Debe seleccionar un producto.',
        'return_product_id.exists' => 'El producto seleccionado no es válido.',
        'return_type.required' => 'Debe seleccionar un tipo de devolución.',
        'return_type.in' => 'El tipo de devolución seleccionado no es válido.',
        'return_reason.required' => 'Debe proporcionar una razón para la devolución.',
        'return_reason.max' => 'La razón no puede exceder 255 caracteres.',
        'return_quantity.required' => 'Debe especificar la cantidad.',
        'return_quantity.integer' => 'La cantidad debe ser un número entero.',
        'return_quantity.min' => 'La cantidad debe ser mayor a 0.',
    ];

    public function mount($employee_id = null): void
    {
        $this->employees = Employee::orderBy('first_name')->get();
        $this->reconciliation_date = now()->format('Y-m-d');
        $this->banks = BankEnum::options();
        $this->products = Product::where('is_active', true)->get();
        $this->return_types = ProductReturnTypeEnum::getOptions();
        $this->loadTypePrices();

        // Establecer "Producto dañado" como valor predeterminado
        $this->return_type = ProductReturnTypeEnum::DAMAGED->value;

        if ($employee_id) {
            $this->employee_id = $employee_id;
            $this->loadEmployeeDataOnly();
        }

        $this->loadDeposits();
        $this->loadBills();
    }

    public function updatedEmployeeId()
    {
        if ($this->employee_id) {
            // Verificar si existe un cuadre para la fecha seleccionada y está completado
            $selectedDate = Carbon::parse($this->reconciliation_date);
            $existing = DailySalesReconciliation::getForEmployeeAndDate($this->employee_id, $selectedDate);

            if ($existing && $existing->status === ReconciliationStatusEnum::COMPLETED) {
                session()->flash('warning', 'El empleado seleccionado ya tiene un cuadre completado para la fecha seleccionada.');
            }

            // Solo cargamos los datos del empleado pero no creamos el cuadre automáticamente
            $this->loadEmployeeDataOnly();
        } else {
            $this->resetData();
        }
    }

    public function updatedReconciliationDate()
    {
        if ($this->employee_id && $this->reconciliation_date) {
            // Verificar si existe un cuadre para la nueva fecha seleccionada
            $selectedDate = Carbon::parse($this->reconciliation_date);
            $existing = DailySalesReconciliation::getForEmployeeAndDate($this->employee_id, $selectedDate);

            if ($existing && $existing->status === ReconciliationStatusEnum::COMPLETED) {
                session()->flash('warning', 'El empleado seleccionado ya tiene un cuadre completado para la fecha seleccionada.');
            }

            // Recargar los datos para la nueva fecha
            $this->loadEmployeeDataOnly();
            $this->loadRemainingProducts();
        }
    }

    // Método para cargar solo los datos del empleado sin crear el cuadre
    protected function loadEmployeeDataOnly()
    {
        if (!$this->employee_id) {
            return;
        }

        $selectedDate = Carbon::parse($this->reconciliation_date);

        // Verificar si ya existe un cuadre para la fecha seleccionada
        $existing = DailySalesReconciliation::getForEmployeeAndDate($this->employee_id, $selectedDate);

        if ($existing) {
            $this->current_reconciliation = $existing;
            $this->reconciliation_created = true;

            // Si el cuadre está completado, no cargamos los datos
            if ($existing->status === ReconciliationStatusEnum::COMPLETED) {
                return;
            }

            // Cargar el efectivo recibido si existe
            $this->cash_received = $existing->total_cash_received;

            $this->loadDeposits();
            $this->loadBills();
        }

        // Load sales for the selected date and employee
        $this->sales = Sale::with(['client'])
            ->notCancelled()
            ->where('employee_id', $this->employee_id)
            ->whereDate('sale_date', $selectedDate)
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'time' => $sale->sale_date->format('H:i'),
                    'client' => $sale->client ? $sale->client->business_name : 'Cliente General',
                    'subtotal' => $sale->subtotal,
                    'total' => $sale->total_amount,
                    'type' => $sale->payment_term->getLabel(),
                    'payment_method' => $sale->payment_method->getLabel(),
                ];
            })->toArray();

        // Load today's payments/collections for the selected employee
        $this->payments = Payment::with(['model.sale.client'])
            ->where('model_type', 'App\\Models\\AccountReceivable')
            ->whereHas('model.sale', function ($query) {
                $query->where('employee_id', $this->employee_id);
            })
            ->whereDate('payment_date', $selectedDate)
            ->get()
            ->map(function ($payment) {
                $accountReceivable = $payment->model;
                return [
                    'id' => $payment->id,
                    'time' => $payment->payment_date->format('H:i'),
                    'client' => $accountReceivable->sale->client->business_name ?? 'Cliente General',
                    'amount' => $payment->amount,
                    'method' => $payment->payment_method->getLabel(),
                ];
            })->toArray();

        $this->loadReturns();
        $this->loadMovements();
        $this->loadRemainingProducts();
        $this->calculateTotals();
    }

    // Método completo que carga datos y crea el cuadre
    protected function loadEmployeeData()
    {
        $this->loadEmployeeDataOnly();

        if (!$this->reconciliation_created) {
            $this->createPendingReconciliation();
        }
    }

    protected function calculateTotals()
    {
        $this->total_cash_sales = collect($this->sales)
            ->where('type', PaymentTermEnum::CASH->getLabel())
            ->sum('total');

        $this->total_credit_sales = collect($this->sales)
            ->where('type', PaymentTermEnum::CREDIT->getLabel())
            ->sum('total');

        $this->total_sales = $this->total_cash_sales + $this->total_credit_sales;

        // Calcular cobros desglosados por método de pago
        $this->total_cash_collections = collect($this->payments)
            ->where('method', PaymentTypeEnum::CASH->getLabel())
            ->sum('amount');

        $this->total_deposit_collections = collect($this->payments)
            ->where('method', PaymentTypeEnum::DEPOSIT->getLabel())
            ->sum('amount');

        $this->total_collections = collect($this->payments)
            ->sum('amount');

        // Calcular ventas pagadas con depósitos
        $this->total_deposit_sales = collect($this->sales)
            ->where('type', PaymentTermEnum::CASH->getLabel())
            ->where('payment_method', PaymentTypeEnum::DEPOSIT->getLabel())
            ->sum('total');

        // Calcular efectivo esperado (solo ventas al contado con método de pago en efectivo + cobros en efectivo - gastos)
        $cash_only_sales = collect($this->sales)
            ->where('type', PaymentTermEnum::CASH->getLabel())
            ->where('payment_method', PaymentTypeEnum::CASH->getLabel())
            ->sum('total');

        // Calcular total de gastos
        $this->calculateBillTotals();

        // El efectivo esperado se reduce por los gastos realizados, pero nunca puede ser negativo
        $this->total_cash_expected = max(0, $cash_only_sales + $this->total_cash_collections) - $this->total_bills + $this->product_shortage_total;

        // Calcular depósitos esperados (ventas pagadas con depósitos + cobros en depósitos)
        $this->total_deposit_expected = $this->total_deposit_sales + $this->total_deposit_collections;

        // Calcular diferencia de efectivo (efectivo recibido - efectivo esperado)
        $this->calculateCashDifference();

        // Calcular total de depósitos realizados y diferencia de depósitos
        $this->calculateDepositTotals();
    }

    // Método para iniciar el cuadre (llamado desde el botón)
    public function initializeReconciliation()
    {
        if (!$this->employee_id) {
            session()->flash('error', 'Debe seleccionar un empleado para inicializar el cuadre.');
            return;
        }

        $this->current_reconciliation = $this->createPendingReconciliation();
        $this->loadDeposits();

        session()->flash('success', 'Cuadre inicializado correctamente.');
    }

    protected function createPendingReconciliation()
    {
        if (!$this->employee_id) {
            return null;
        }

        $today = $this->reconciliation_date;

        // Check if reconciliation already exists for today
        $existing = DailySalesReconciliation::getForEmployeeAndDate($this->employee_id, $today);

        if ($existing) {
            $this->current_reconciliation = $existing;
            $this->reconciliation_created = true;
            return $existing;
        }

        // Calcular el efectivo esperado y la diferencia de efectivo
        $this->calculateCashDifference();

        // Calcular cash_sales (ventas al contado en efectivo, excluyendo depósitos)
        $cash_sales = $this->total_cash_sales - $this->total_deposit_sales;

        try {
            // Create new pending reconciliation
            $reconciliation = DailySalesReconciliation::create([
                'employee_id' => $this->employee_id,
                'cashier_id' => Auth::id(),
                'branch_id' => Auth::user()->employee?->branch_id ?? 1, // Default branch
                'reconciliation_date' => $today,
                'total_cash_sales' => $this->total_cash_sales,
                'cash_sales' => $cash_sales,
                'total_credit_sales' => $this->total_credit_sales,
                'deposit_sales' => $this->total_deposit_sales,
                'total_sales' => $this->total_sales,
                'cash_collections' => $this->total_cash_collections,
                'deposit_collections' => $this->total_deposit_collections,
                'total_collections' => $this->total_collections,
                'total_cash_received' => $this->cash_received,
                'total_deposits' => 0, // Will be filled later
                'total_cash_expected' => $this->total_cash_expected,
                'total_deposit_expected' => $this->total_deposit_expected,
                'cash_difference' => $this->cash_difference,
                'status' => ReconciliationStatusEnum::PENDING,
                'notes' => null,
                'product_shortage_total' => $this->product_shortage_total,
                'type_price_id' => $this->type_price_id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate entry error
            if ($e->getCode() === '23000') {
                // Check if it's our unique constraint
                if (str_contains($e->getMessage(), 'unique_employee_date_reconciliation')) {
                    session()->flash('error', 'Ya existe un cuadre para este empleado en la fecha seleccionada.');
                    return null;
                }
            }

            // Re-throw other database errors
            throw $e;
        }

        $this->current_reconciliation = $reconciliation;
        $this->reconciliation_created = true;

        return $reconciliation;
    }

    protected function resetData()
    {
        $this->sales = [];
        $this->payments = [];
        $this->total_cash_sales = 0.0;
        $this->total_credit_sales = 0.0;
        $this->total_deposit_sales = 0.0;
        $this->total_sales = 0.0;
        $this->total_collections = 0.0;
        $this->total_cash_collections = 0.0;
        $this->total_deposit_collections = 0.0;
        $this->total_cash_expected = 0.0;
        $this->total_deposit_expected = 0.0;
        $this->cash_received = 0.0;
        $this->cash_difference = 0.0;
        $this->reconciliation_created = false;
        $this->current_reconciliation = null;
        $this->deposits = [];
        $this->resetDepositForm();
        $this->bills = [];
        $this->resetBillForm();
        $this->returns = [];
        $this->resetReturnForm();
        $this->movements = [];
        $this->remaining_products = [];
        $this->type_price_id = null;
        $this->product_shortage_total = 0.0;
    }

    protected function getPaymentMethodLabel($paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash' => 'Efectivo',
            'deposit' => 'Depósito',
            'card' => 'Tarjeta',
            'transfer' => 'Transferencia',
            default => 'Otro'
        };
    }

    // Método para actualizar el efectivo recibido
    public function updateCashReceived($value)
    {
        $this->cash_received = floatval($value);
        $this->calculateCashDifference();

        if ($this->current_reconciliation) {
            $this->current_reconciliation->update([
                'total_cash_received' => $this->cash_received,
                'cash_difference' => $this->cash_difference
            ]);
        }
    }

    // Método para calcular la diferencia de efectivo
    protected function calculateCashDifference()
    {
        // Restamos los gastos para reflejarlos en la diferencia de efectivo
        $this->cash_difference = ($this->cash_received - $this->total_cash_expected);
    }

    protected function calculateDepositTotals()
    {
        // Calcular el total de depósitos realizados
        $this->deposits_made = collect($this->deposits)->sum('amount');

        // Calcular la diferencia entre depósitos realizados y esperados
        $this->deposit_difference = $this->deposits_made - $this->total_deposit_expected;
    }

    // Método para guardar el cuadre (llamado desde el botón)
    public function saveReconciliation()
    {
        // Validar que se haya seleccionado un precio de lista cuando hay productos sobrantes
        if (!empty($this->remaining_products) && empty($this->type_price_id)) {
            session()->flash('error', 'Debe seleccionar un precio de lista porque existen productos sobrantes.');
            return;
        }

        // Validar que se haya seleccionado un empleado
        if (empty($this->employee_id)) {
            session()->flash('error', 'Debe seleccionar un empleado para crear el cuadre');
            return;
        }

        // Validar que se haya ingresado el efectivo recibido
        if ($this->cash_received <= 0) {
            session()->flash('error', 'Debe ingresar el efectivo recibido');
            return;
        }

        if (!$this->current_reconciliation) {
            // Si no hay un cuadre inicializado, lo creamos primero
            $this->createPendingReconciliation();
        }

        if ($this->current_reconciliation) {
            // Iniciar una transacción para asegurar que todas las operaciones se realicen de forma atómica
            DB::beginTransaction();

            try {
                // Calcular cash_sales (ventas en efectivo sin depósitos)
                $cash_sales = $this->total_cash_sales - $this->total_deposit_sales;

                // Actualizar el estado del cuadre a COMPLETED
                $this->current_reconciliation->update([
                    'status' => ReconciliationStatusEnum::COMPLETED,
                    'total_cash_received' => $this->cash_received,
                    'total_cash_expected' => $this->total_cash_expected,
                    'cash_difference' => $this->cash_difference,
                    'total_deposits' => $this->deposits_made,
                    'total_deposit_expected' => $this->total_deposit_expected,
                    'deposit_difference' => $this->deposit_difference,
                    'total_cash_sales' => $this->total_cash_sales,
                    'cash_sales' => $cash_sales,
                    'total_credit_sales' => $this->total_credit_sales,
                    'deposit_sales' => $this->total_deposit_sales,
                    'total_sales' => $this->total_sales,
                    'cash_collections' => $this->total_cash_collections,
                    'deposit_collections' => $this->total_deposit_collections,
                    'total_collections' => $this->total_collections,
                    'total_bills' => $this->total_bills,
                    'product_shortage_total' => $this->product_shortage_total,
                    'type_price_id' => $this->type_price_id,
                ]);

                // Recargar el cuadre para tener los datos actualizados
                $this->current_reconciliation->refresh();

                // Confirmar la transacción
                DB::commit();

                // Mostrar mensaje de éxito
                session()->flash('success', 'Cuadre guardado correctamente');

                // Redireccionar a la lista de cuadres
                $this->redirect(\App\Filament\Resources\DailySalesReconciliationResource::getUrl('index'));
            } catch (\Exception $e) {
                // Si ocurre algún error, revertir la transacción
                DB::rollBack();

                // Mostrar mensaje de error
                session()->flash('error', 'Error al guardar el cuadre: ' . $e->getMessage());
            }
        }
    }

    // Método para cargar los depósitos existentes
    protected function loadDeposits()
    {
        if ($this->current_reconciliation) {
            $this->deposits = Deposit::where('model_id', $this->current_reconciliation->id)
                ->get()
                ->map(function ($deposit) {
                    return [
                        'id' => $deposit->id,
                        'amount' => $deposit->amount,
                        'bank' => $deposit->bank->value,
                        'reference_number' => $deposit->reference_number,
                        'description' => "Déposito generado en venta",
                    ];
                })->toArray();

            // Actualizar el total de depósitos realizados y calcular la diferencia
            $this->calculateDepositTotals();
        } else {
            $this->deposits = [];
            $this->deposits_made = 0.0;
            $this->deposit_difference = 0.0;
        }
    }

    // Método para guardar un nuevo depósito
    public function saveDeposit()
    {
        $this->validate([
            'deposit_amount' => 'required|numeric|min:0.01',
            'deposit_bank' => 'required|string',
            'deposit_reference' => 'required|string',
        ], [
            'deposit_amount.required' => 'El monto es requerido',
            'deposit_amount.numeric' => 'El monto debe ser un número',
            'deposit_amount.min' => 'El monto debe ser mayor a 0',
            'deposit_bank.required' => 'El banco es requerido',
            'deposit_reference.required' => 'La referencia es requerida',
        ]);

        // Si no hay un cuadre inicializado, lo creamos primero
        if (!$this->current_reconciliation) {
            $this->createPendingReconciliation();
        }

        // Iniciar una transacción para asegurar que todas las operaciones se realicen de forma atómica
        DB::beginTransaction();

        try {
            // Crear el depósito asociado al cuadre actual
            Deposit::create([
                'amount' => $this->deposit_amount,
                'bank' => $this->deposit_bank,
                'reference_number' => $this->deposit_reference,
                'model_id' => $this->current_reconciliation->id,
                'branch_id' => Auth::user()->employee?->branch_id ?? 1,
            ]);

            // Actualizar el total de depósitos en el cuadre
            $total_deposits = Deposit::where('model_id', $this->current_reconciliation->id)->sum('amount');
            $this->deposits_made = $total_deposits;
            $this->deposit_difference = $this->deposits_made - $this->total_deposit_expected;

            $this->current_reconciliation->update([
                'total_deposits' => $total_deposits,
                'deposit_difference' => $this->deposit_difference
            ]);

            // Confirmar la transacción
            DB::commit();

            // Limpiar los campos del formulario de depósito
            $this->resetDepositForm();

            // Mostrar mensaje de éxito
            session()->flash('success', 'Depósito guardado correctamente');
        } catch (\Exception $e) {
            // Si ocurre algún error, revertir la transacción
            DB::rollBack();

            // Mostrar mensaje de error
            session()->flash('error', 'Error al guardar el depósito: ' . $e->getMessage());
        }

        // Recargar los depósitos
        $this->loadDeposits();
    }

    // Método para eliminar un depósito
    public function deleteDeposit($depositId)
    {
        $deposit = Deposit::find($depositId);

        if ($deposit && $deposit->model_id == $this->current_reconciliation->id) {
            // Iniciar una transacción para asegurar que todas las operaciones se realicen de forma atómica
            DB::beginTransaction();

            try {
                // Eliminar el depósito
                $deposit->delete();

                // Actualizar el total de depósitos en el cuadre
                $total_deposits = Deposit::where('model_id', $this->current_reconciliation->id)->sum('amount');
                $this->deposits_made = $total_deposits;
                $this->deposit_difference = $this->deposits_made - $this->total_deposit_expected;

                $this->current_reconciliation->update([
                    'total_deposits' => $total_deposits,
                    'deposit_difference' => $this->deposit_difference
                ]);

                // Confirmar la transacción
                DB::commit();

                // Mostrar mensaje de éxito
                session()->flash('success', 'Depósito eliminado correctamente');
            } catch (\Exception $e) {
                // Si ocurre algún error, revertir la transacción
                DB::rollBack();

                // Mostrar mensaje de error
                session()->flash('error', 'Error al eliminar el depósito: ' . $e->getMessage());
            }

            // Recargar los depósitos
            $this->loadDeposits();
        }
    }

    // Método para resetear el formulario de depósito
    protected function resetDepositForm()
    {
        $this->deposit_amount = 0.0;
        $this->deposit_bank = null;
        $this->deposit_reference = null;
    }

    // Método para guardar un gasto
    public function saveBill()
    {
        $this->validate([
            'bill_description' => 'required|string|max:255',
            'bill_amount' => 'required|numeric|min:0.01',
            'bill_reference' => 'nullable|string|max:255',
        ], [
            'bill_description.required' => 'La descripción es requerida',
            'bill_amount.required' => 'El monto es requerido',
            'bill_amount.numeric' => 'El monto debe ser un número',
            'bill_amount.min' => 'El monto debe ser mayor a 0',
        ]);

        // Si no hay un cuadre inicializado, lo creamos primero
        if (!$this->current_reconciliation) {
            $this->createPendingReconciliation();
        }

        // Iniciar una transacción para asegurar que todas las operaciones se realicen de forma atómica
        DB::beginTransaction();

        try {
            // Crear el gasto asociado al cuadre actual
            Bill::create([
                'description' => $this->bill_description,
                'amount' => $this->bill_amount,
                'reference_number' => $this->bill_reference,
                'model_id' => $this->current_reconciliation->id,
                'branch_id' => Auth::user()->employee?->branch_id ?? 1,
            ]);

            // Recalcular totales
            $this->calculateBillTotals();
            $this->calculateTotals();

            // Confirmar la transacción
            DB::commit();

            // Limpiar los campos del formulario de gasto
            $this->resetBillForm();

            // Mostrar mensaje de éxito
            session()->flash('success', 'Gasto guardado correctamente');
        } catch (\Exception $e) {
            // Si ocurre algún error, revertir la transacción
            DB::rollBack();

            // Mostrar mensaje de error
            session()->flash('error', 'Error al guardar el gasto: ' . $e->getMessage());
        }

        // Recargar los gastos
        $this->loadBills();
    }

    // Método para eliminar un gasto
    public function deleteBill($billId)
    {
        $bill = Bill::find($billId);

        if ($bill && $bill->model_id == $this->current_reconciliation->id) {
            // Iniciar una transacción para asegurar que todas las operaciones se realicen de forma atómica
            DB::beginTransaction();

            try {
                // Eliminar el gasto
                $bill->delete();

                // Recalcular totales
                $this->calculateBillTotals();
                $this->calculateTotals();

                // Confirmar la transacción
                DB::commit();

                // Mostrar mensaje de éxito
                session()->flash('success', 'Gasto eliminado correctamente');
            } catch (\Exception $e) {
                // Si ocurre algún error, revertir la transacción
                DB::rollBack();

                // Mostrar mensaje de error
                session()->flash('error', 'Error al eliminar el gasto: ' . $e->getMessage());
            }

            // Recargar los gastos
            $this->loadBills();
        }
    }

    // Método para resetear el formulario de gasto
    protected function resetBillForm()
    {
        $this->bill_description = null;
        $this->bill_amount = 0.0;
        $this->bill_reference = null;
    }

    // Método para calcular el total de gastos
    protected function calculateBillTotals()
    {
        if ($this->current_reconciliation) {
            $this->total_bills = Bill::where('model_id', $this->current_reconciliation->id)->sum('amount');
        } else {
            $this->total_bills = 0.0;
        }
    }

    // Método para cargar los gastos del cuadre actual
    protected function loadBills()
    {
        if ($this->current_reconciliation) {
            $this->bills = Bill::where('model_id', $this->current_reconciliation->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($bill) {
                    return [
                        'id' => $bill->id,
                        'description' => $bill->description,
                        'amount' => $bill->amount,
                        'reference_number' => $bill->reference_number,
                        'created_at' => $bill->created_at->format('H:i'),
                    ];
                })->toArray();
        } else {
            $this->bills = [];
        }

        $this->calculateBillTotals();
    }

    public function loadReturns(): void
    {
        // Si tenemos un cuadre específico, cargar devoluciones por reconciliation_id para mayor precisión
        if ($this->current_reconciliation) {
            $this->returns = ProductReturn::with('product')
                ->where('reconciliation_id', $this->current_reconciliation->id)
                ->get()
                ->map(function ($return) {
                    return [
                        'id' => $return->id,
                        'product_name' => $return->product->name,
                        'quantity' => $return->quantity,
                        'type' => $return->type->getLabel(),
                        'reason' => $return->reason,
                        'affects_inventory' => $return->affects_inventory ? 'Sí' : 'No',
                        'created_at' => $return->created_at->format('H:i:s'),
                    ];
                })->toArray();
            return;
        }

        // Fallback: si no hay cuadre, usar employee_id y fecha como antes
        if (!$this->employee_id || !$this->reconciliation_date) {
            $this->returns = [];
            return;
        }
    }

    public function loadMovements(): void
    {
        if (!$this->employee_id || !$this->reconciliation_date) {
            $this->movements = [];
            return;
        }

        $assignedProduct = AssignedProduct::where('employee_id', $this->employee_id)
            ->whereDate('date', $this->reconciliation_date)
            ->first();

        if (!$assignedProduct) {
            $this->movements = [];
            return;
        }

        $this->movements = AssignedProductMovement::whereHas('detailAssignedProduct', function ($query) use ($assignedProduct) {
            $query->where('assigned_products_id', $assignedProduct->id);
        })
            ->with(['detailAssignedProduct.product', 'sale'])
            ->get()
            ->map(function ($movement) {
                return [
                    'id' => $movement->id,
                    'product_name' => $movement->detailAssignedProduct->product->name,
                    'type' => $movement->type->getLabel(),
                    'type_raw' => $movement->type->value,
                    'quantity' => $movement->quantity,
                    'note' => $movement->note,
                    'created_at' => $movement->created_at->format('H:i:s'),
                    'sale_id' => $movement->sale_id,
                    'sale_info' => $movement->sale?->full_invoice_number ?? ($movement->sale_id ? "#{$movement->sale_id}" : null),
                ];
            })->toArray();
    }

    // Método para cargar productos sobrantes del empleado seleccionado
    public function loadRemainingProducts(): void
    {
        if (!$this->employee_id || !$this->reconciliation_date) {
            $this->remaining_products = [];
            return;
        }

        // Obtener productos asignados al empleado en la fecha seleccionada
        $assignedProducts = AssignedProduct::with(['details.product'])
            ->where('employee_id', $this->employee_id)
            ->assignmentsDate($this->reconciliation_date)
            ->first();

        if (!$assignedProducts || !$assignedProducts->details) {
            $this->remaining_products = [];
            return;
        }

        // Mapear los detalles de productos asignados con cálculos de sobrantes
        $this->remaining_products = $assignedProducts->details
            ->map(function ($detail) {
                $quantityAssigned = $detail->quantity;
                $quantitySold = (int) $detail->sale_quantity ?? 0;
                $returnedQuantity = (float) $detail->returned_quantity ?? 0;
                $changesQuantity = (float) $detail->changes_quantity ?? 0;
                $royaltiesQuantity = (float) $detail->royalties_quantity ?? 0;
                $remaining = $quantityAssigned - $quantitySold - $returnedQuantity - $changesQuantity - $royaltiesQuantity;

                return [
                    'id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_name' => $detail->product->name ?? 'Producto no encontrado',
                    'product_code' => $detail->product->code ?? 'N/A',
                    'quantity_assigned' => $quantityAssigned,
                    'quantity_sold' => $quantitySold,
                    'returned_quantity' => $returnedQuantity,
                    'changes_quantity' => $changesQuantity,
                    'royalties_quantity' => $royaltiesQuantity,
                    'remaining' => $remaining,
                ];
            })
            ->filter(function ($item) {
                // Mostrar productos que tengan cantidad asignada (para permitir edición de devoluciones)
                return $item['quantity_assigned'] > 0;
            })
            ->values()
            ->toArray();

        if ($this->type_price_id) {
            $this->recalculateProductShortage();
        }
    }

    public function addReturn(): void
    {
        $this->validate([
            'return_product_id' => 'required|exists:products,id',
            'return_type' => 'required|in:damaged,returned',
            'return_reason' => 'required|string|max:255',
            'return_quantity' => 'required|integer|min:1',
        ]);

        try {
            DB::transaction(function () {
                // Si no hay un cuadre inicializado, lo creamos primero
                if (!$this->current_reconciliation) {
                    $this->createPendingReconciliation();
                }

                // Crear la devolución
                $productReturn = ProductReturn::create([
                    'product_id' => $this->return_product_id,
                    'employee_id' => $this->employee_id,
                    'reconciliation_id' => $this->current_reconciliation->id,
                    'quantity' => $this->return_quantity,
                    'type' => ProductReturnTypeEnum::from($this->return_type),
                    'reason' => $this->return_reason,
                    'affects_inventory' => $this->return_affects_inventory,
                ]);

                // Registrar el movimiento de inventario
                $returnService = new ProductReturnService();
                $returnService->registerInventoryMovement($productReturn);
            });

            $this->resetReturnForm();
            $this->loadReturns();
            session()->flash('success', 'Devolución registrada exitosamente.');
        } catch (\Exception $e) {
            session()->flash('error', 'Error al registrar la devolución: ' . $e->getMessage());
        }
    }

    public function deleteReturn(int $returnId): void
    {
        try {
            DB::transaction(function () use ($returnId) {
                $productReturn = ProductReturn::findOrFail($returnId);

                // Revertir el movimiento de inventario antes de eliminar
                $returnService = new ProductReturnService();
                $returnService->reverseInventoryMovement($productReturn);

                // Eliminar la devolución
                $productReturn->delete();
            });

            $this->loadReturns();
            session()->flash('success', 'Devolución eliminada exitosamente.');
        } catch (\Exception $e) {
            session()->flash('error', 'Error al eliminar la devolución: ' . $e->getMessage());
        }
    }

    public function resetReturnForm(): void
    {
        $this->return_product_id = null;
        $this->return_type = ProductReturnTypeEnum::DAMAGED->value;
        $this->return_reason = null;
        $this->return_quantity = 1;
        $this->return_affects_inventory = true;
        $this->resetProductSearch();
    }

    public function resetProductSearch(): void
    {
        $this->product_search = '';
    }

    public $show_product_dropdown = false;
    public $filtered_products = [];
    public $selected_product = null;

    public function updatedProductSearch(): void
    {
        // No ejecutar búsqueda si hay un producto seleccionado
        if ($this->selected_product) {
            return;
        }

        if (strlen($this->product_search) >= 3) {
            $this->searchProducts();
            $this->show_product_dropdown = true;
        } else {
            $this->filtered_products = [];
            $this->show_product_dropdown = false;
        }
    }

    private function searchProducts()
    {
        $this->filtered_products = Product::where('name', 'like', '%' . $this->product_search . '%')
            ->orWhere('code', 'like', '%' . $this->product_search . '%')
            ->limit(10)
            ->get()
            ->toArray();
    }

    public function selectProduct($productId)
    {
        $product = Product::find($productId);
        if ($product) {
            $this->selected_product = $product;
            $this->product_search = $product->name;
            $this->return_product_id = $product->id;
            $this->show_product_dropdown = false;
            $this->filtered_products = [];
        }
    }

    public function clearProductSelection()
    {
        $this->selected_product = null;
        $this->product_search = '';
        $this->return_product_id = null;
        $this->show_product_dropdown = false;
        $this->filtered_products = [];
    }

    // Método para actualizar la cantidad retornada de un producto
    public function updateReturnedQuantity($detailId, $returnedQuantity): void
    {
        try {
            DB::transaction(function () use ($detailId, $returnedQuantity) {
                $detail = DetailAssignedProduct::find($detailId);

                if (!$detail) {
                    throw new \Exception('Producto no encontrado.');
                }

                // Validar que la cantidad retornada no sea negativa
                if ($returnedQuantity < 0) {
                    throw new \Exception('La cantidad retornada no puede ser negativa.');
                }

                // Validar que la cantidad retornada no exceda la cantidad asignada menos la vendida
                $maxReturnable = $detail->quantity - $detail->sale_quantity;
                if ($returnedQuantity > $maxReturnable) {
                    throw new \Exception('La cantidad retornada no puede exceder la cantidad disponible.');
                }

                // Si no hay un cuadre inicializado, lo creamos primero
                if (!$this->current_reconciliation) {
                    $this->createPendingReconciliation();
                }

                // Actualizar la cantidad retornada en el detalle
                $detail->update(['returned_quantity' => $returnedQuantity]);

                // Crear registro de devolución con la cantidad especificada
                if ($returnedQuantity > 0) {
                    $this->createProductReturn($detail, $returnedQuantity);
                }
            });

            // Recargar los productos sobrantes para reflejar los cambios
            $this->loadRemainingProducts();
            $this->loadReturns();

            session()->flash('success', 'Cantidad retornada registrada correctamente.');

        } catch (\Exception $e) {
            session()->flash('error', 'Error al registrar la cantidad retornada: ' . $e->getMessage());
        }
    }

    /**
     * Retornar todos los productos sobrantes que no tengan cantidad retornada
     */
    public function returnAllRemainingProducts(): void
    {
        try {
            $processedCount = 0;
            $errors = [];

            DB::transaction(function () use (&$processedCount, &$errors) {
                foreach ($this->remaining_products as $product) {
                    // Solo procesar productos que tengan cantidad sobrante y no tengan cantidad retornada
                    if ($product['remaining'] > 0 && $product['returned_quantity'] == 0) {
                        try {
                            // Usar el método existente updateReturnedQuantity
                            $this->updateReturnedQuantity($product['id'], $product['remaining']);
                            $processedCount++;
                        } catch (\Exception $e) {
                            $errors[] = "Error con {$product['product_name']}: " . $e->getMessage();
                        }
                    }
                }
            });

            // Recargar los datos para reflejar los cambios
            $this->loadRemainingProducts();
            $this->loadReturns();

            if ($processedCount > 0) {
                $message = "Se procesaron {$processedCount} productos como retornados exitosamente.";
                if (!empty($errors)) {
                    $message .= " Algunos productos tuvieron errores: " . implode(', ', $errors);
                }
                session()->flash('success', $message);
            } else {
                session()->flash('info', 'No hay productos sobrantes para retornar o todos ya tienen cantidad retornada.');
            }

        } catch (\Exception $e) {
            session()->flash('error', 'Error al procesar los productos: ' . $e->getMessage());
        }
    }

    /**
     * Crear un registro de devolución de producto
     */
    private function createProductReturn(DetailAssignedProduct $detail, float $quantity): void
    {
        try {
            // Crear el registro de devolución
            $productReturn = ProductReturn::create([
                'product_id' => $detail->product_id,
                'employee_id' => $this->employee_id,
                'reconciliation_id' => $this->current_reconciliation->id,
                'quantity' => $quantity,
                'type' => ProductReturnTypeEnum::RETURNED,
                'reason' => 'Registro desde cuadre de caja',
                'affects_inventory' => true,
            ]);

            // Registrar el movimiento de inventario
            $returnService = new ProductReturnService();
            $returnService->registerInventoryMovement($productReturn);

            Log::info('Devolución registrada', [
                'product_return_id' => $productReturn->id,
                'product_id' => $detail->product_id,
                'quantity' => $quantity,
                'employee_id' => $this->employee_id
            ]);

        } catch (\Exception $e) {
            Log::error('Error al registrar devolución', [
                'product_id' => $detail->product_id,
                'quantity' => $quantity,
                'employee_id' => $this->employee_id,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Error al procesar la devolución: ' . $e->getMessage());
        }
    }

    public function loadTypePrices(): void
    {
        $this->type_prices = TypePrice::orderBy('name')->get()->toArray();
    }

    public function updatedTypePriceId(): void
    {
        $this->recalculateProductShortage();
        $this->calculateTotals();
    }

    public function recalculateProductShortage(): void
    {
        if (!$this->type_price_id || empty($this->remaining_products)) {
            $this->remaining_products = array_map(function ($product) {
                $product['shortage_cash'] = 0.0;
                $product['shortage_cash_unit_price'] = 0.0;
                return $product;
            }, $this->remaining_products);
            $this->product_shortage_total = 0.0;
            return;
        }

        $productIds = array_column($this->remaining_products, 'product_id');

        $baseUnits = ProductUnit::whereIn('product_id', $productIds)
            ->where('is_base_unit', true)
            ->get()
            ->keyBy('product_id');

        $prices = ProductPrice::where('type_price_id', $this->type_price_id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        $total = 0.0;

        $this->remaining_products = array_map(function ($product) use ($baseUnits, $prices, &$total) {
            $productId = $product['product_id'];
            $remaining = (float) $product['remaining'];

            $baseUnit = $baseUnits->get($productId);
            $productPrices = $prices->get($productId);

            if (!$baseUnit || !$productPrices || $remaining <= 0) {
                $product['shortage_cash_unit_price'] = 0.0;
                $product['shortage_cash'] = 0.0;
                return $product;
            }

            $priceRecord = $productPrices->firstWhere('product_unit_id', $baseUnit->id);
            $unitPrice = $priceRecord ? (float) $priceRecord->price : 0.0;

            $product['shortage_cash_unit_price'] = $unitPrice;
            $product['shortage_cash'] = round($remaining * $unitPrice, 2);
            $total += $product['shortage_cash'];

            return $product;
        }, $this->remaining_products);

        $this->product_shortage_total = round($total, 2);
    }

    public function render()
    {
        return view('livewire.reconciliations.create-reconciliation');
    }
}