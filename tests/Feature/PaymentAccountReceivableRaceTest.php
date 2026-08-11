<?php

use App\Enums\AccountReceivableStatusEnum;
use App\Enums\PaymentTermEnum;
use App\Models\AccountReceivable;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\Product;
use App\Models\TypePrice;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\SaleCancellationService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Regresión: un pago no debe poder registrarse sobre una cuenta por cobrar
 * ya anulada (R5), ni una anulación debe poder pisar una cuenta que acaba
 * de recibir un pago real. PaymentService::processPayment validaba el
 * estado de la CxC sobre el objeto recibido por parámetro, leído ANTES de
 * abrir su propia transacción — si ese objeto quedaba desactualizado
 * (p. ej. SaleCancellationService la anuló justo después de que el
 * controlador la cargara, fuera de esta transacción), la validación de
 * "sólo cuentas pendientes" corría contra el estado viejo (PENDING en
 * memoria) en vez del real (CANCELLED en la base), y el pago se registraba
 * igual, revirtiendo la anulación en silencio.
 */

function makeCreditSaleWithReceivable(): array
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 80,
        'sale_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
        'returned_quantity' => 0,
    ]);

    Auth::login($user);

    $sale = app(SaleService::class)->createSale(
        [
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'sale_date' => now()->toDateString(),
            'cash_amount' => 0,
            'payment_method' => 'cash',
            'payment_term' => PaymentTermEnum::CREDIT->value,
            'branch_id' => $branch->id,
        ],
        [[
            'origin' => 'api',
            'product_id' => $product->id,
            'name' => $product->name,
            'type_price_id' => TypePrice::factory()->create()->id,
            'unit_name' => 'Unidad',
            'quantity' => 10,
            'unit_price_without_tax' => 10,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
        ]],
    );

    return compact('sale');
}

it('rejects a payment on an account receivable that was cancelled after being loaded (stale in-memory object)', function () {
    $scenario = makeCreditSaleWithReceivable();
    $accountReceivable = $scenario['sale']->fresh()->accountReceivable;

    // Simula lo que hace el controlador: carga la CxC (status PENDING en
    // este objeto PHP)...
    $staleAccountReceivable = AccountReceivable::find($accountReceivable->id);
    expect($staleAccountReceivable->status)->toBe(AccountReceivableStatusEnum::PENDING);

    // ...y ANTES de llamar a processPayment(), otra request la anula.
    app(SaleCancellationService::class)->cancel($scenario['sale']->fresh(), 'Anulación concurrente');
    expect($accountReceivable->fresh()->status)->toBe(AccountReceivableStatusEnum::CANCELLED);

    // processPayment() recibe el objeto viejo (todavía en memoria como
    // PENDING) — debe rechazar el pago igual, porque relee y bloquea la
    // fila dentro de su propia transacción en vez de confiar en el estado
    // que llegó por parámetro.
    expect(fn () => PaymentService::processPayment($staleAccountReceivable, [
        'amount' => 20,
        'payment_date' => now(),
        'payment_method' => 'cash',
        'notes' => null,
    ]))->toThrow(Exception::class, 'Solo se pueden registrar pagos en cuentas pendientes');

    $fresh = $accountReceivable->fresh();
    expect($fresh->status)->toBe(AccountReceivableStatusEnum::CANCELLED)
        ->and($fresh->payments()->count())->toBe(0);
});

it('still allows a legitimate payment on a pending account receivable through the same locked path', function () {
    $scenario = makeCreditSaleWithReceivable();
    $accountReceivable = $scenario['sale']->fresh()->accountReceivable;

    $result = PaymentService::processPayment($accountReceivable, [
        'amount' => 30,
        'payment_date' => now(),
        'payment_method' => 'cash',
        'notes' => null,
    ]);

    expect($result['success'])->toBeTrue();
    expect((float) $accountReceivable->fresh()->remaining_balance)->toBe(70.0);
});

it('rejects cancelling a sale once its account receivable has just received a payment, even if the AR object held by the caller is stale', function () {
    $scenario = makeCreditSaleWithReceivable();
    $sale = $scenario['sale']->fresh();

    PaymentService::processPayment($sale->accountReceivable, [
        'amount' => 10,
        'payment_date' => now(),
        'payment_method' => 'cash',
        'notes' => null,
    ]);

    expect(fn () => app(SaleCancellationService::class)->cancel($sale->fresh()))
        ->toThrow(\App\Exceptions\SaleCancellationException::class, 'pagos registrados');

    expect($sale->fresh()->status)->not->toBe(\App\Enums\SaleStatusEnum::CANCELLED);
    expect($sale->fresh()->accountReceivable->status)->toBe(AccountReceivableStatusEnum::PENDING);
});
