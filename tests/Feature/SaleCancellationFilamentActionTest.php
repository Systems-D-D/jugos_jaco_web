<?php

use App\Constants\PermissionConstants;
use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use App\Enums\UserRole;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\SaleResource\Pages\ViewSale;
use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\AssignedProduct;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Fase 5 de docs/devflow/specs/2026-08-10-sale-deletion-analysis.md: la
 * acción "Anular" en Filament (tabla de ventas + página de vista), con
 * motivo obligatorio y autorización por rol/pertenencia (§9).
 */

function ensureSalePermissionsExist(): void
{
    foreach ([
        PermissionConstants::SALE_DELETE,
        PermissionConstants::SALE_VIEW,
        PermissionConstants::SALE_LIST,
        PermissionConstants::SALE_CREATE,
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
}

/**
 * Otorga los permisos necesarios para navegar el recurso de ventas en
 * Filament (list/view/create) más el de anular (delete, reutilizado —
 * ver comentario en SaleResource::saleCanBeCancelledByCurrentUser).
 */
function grantSalePermissions(User $user): void
{
    ensureSalePermissionsExist();
    $user->givePermissionTo([
        PermissionConstants::SALE_DELETE,
        PermissionConstants::SALE_VIEW,
        PermissionConstants::SALE_LIST,
        PermissionConstants::SALE_CREATE,
    ]);
}

/**
 * Venta "app" cancelable hoy: mismo patrón que SaleCancellationServiceTest.
 */
function makeCancellableSale(User $actor, array $overrides = []): array
{
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $seller = User::factory()->create(['employee_id' => $employee->id]);
    $client = Client::factory()->create();
    $product = Product::factory()->create(['name' => 'Jugo Naranja', 'is_active' => true]);

    $assignedProduct = AssignedProduct::factory()->create([
        'employee_id' => $employee->id,
        'date' => now(),
    ]);
    $detail = DetailAssignedProduct::factory()->create([
        'assigned_products_id' => $assignedProduct->id,
        'product_id' => $product->id,
        'quantity' => 80,
        'sale_quantity' => 0,
        'changes_quantity' => 0,
        'royalties_quantity' => 0,
        'returned_quantity' => 0,
    ]);

    Auth::login($seller);

    $sale = app(SaleService::class)->createSale(
        array_merge([
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'sale_date' => now()->toDateString(),
            'cash_amount' => 100,
            'payment_method' => 'cash',
            'payment_term' => PaymentTermEnum::CASH->value,
            'branch_id' => $branch->id,
        ], $overrides['header'] ?? []),
        [[
            'origin' => 'api',
            'product_id' => $product->id,
            'name' => $product->name,
            'type_price_id' => \App\Models\TypePrice::factory()->create()->id,
            'unit_name' => 'Unidad',
            'quantity' => 10,
            'unit_price_without_tax' => 10,
            'unit_tax_amount' => 0,
            'line_subtotal' => 100,
            'line_tax_amount' => 0,
            'line_total' => 100,
        ]],
    );

    Auth::login($actor);

    return compact('branch', 'employee', 'seller', 'client', 'product', 'detail', 'sale');
}

// --- Autorización (§9) ---

it('shows the cancel action to an admin and lets them cancel with a mandatory reason', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->getKey()])
        ->assertActionVisible('cancel')
        ->callAction('cancel', data: ['reason' => 'Cliente se arrepintió'])
        ->assertHasNoActionErrors();

    $fresh = $scenario['sale']->fresh();
    expect($fresh->status)->toBe(SaleStatusEnum::CANCELLED)
        ->and($fresh->cancellation_reason)->toBe('Cliente se arrepintió')
        ->and($fresh->cancelled_by)->toBe($admin->id);
});

it('requires a reason to cancel: the action fails validation without one', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->getKey()])
        ->callAction('cancel', data: ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($scenario['sale']->fresh()->status)->not->toBe(SaleStatusEnum::CANCELLED);
});

it('allows a cashier to cancel a sale they created themselves', function () {
    $cashier = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $cashier->assignRole(Role::findOrCreate(UserRole::CASHEER->value, 'web'));
    grantSalePermissions($cashier);

    $scenario = makeCancellableSale($cashier);
    // El helper crea la venta autenticado como el vendedor; la fijamos como
    // creada por el cajero para simular el flujo real de la web.
    $scenario['sale']->update(['created_by' => $cashier->id]);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->assertActionVisible('cancel');
});

it('hides the cancel action from a cashier for a sale created by someone else', function () {
    $cashier = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $cashier->assignRole(Role::findOrCreate(UserRole::CASHEER->value, 'web'));
    grantSalePermissions($cashier);

    $otherCashier = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);

    $scenario = makeCancellableSale($cashier);
    $scenario['sale']->update(['created_by' => $otherCashier->id]);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->assertActionHidden('cancel');
});

it('hides the cancel action without the Sale.delete permission', function () {
    ensureSalePermissionsExist();
    $employed = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $employed->assignRole(Role::findOrCreate(UserRole::EMPLOYED->value, 'web'));
    $employed->givePermissionTo([PermissionConstants::SALE_VIEW, PermissionConstants::SALE_LIST]);
    // Nunca se le otorga Sale.delete: no debe ver el botón de anular.

    $scenario = makeCancellableSale($employed);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->assertActionHidden('cancel');
});

it('hides the cancel action for an already cancelled sale', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin);
    app(\App\Services\SaleCancellationService::class)->cancel($scenario['sale']->fresh(), 'Ya anulada');

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->assertActionHidden('cancel');
});

it('hides the cancel action for an invoiced sale', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin);
    $scenario['sale']->update(['invoice_number' => 1]);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->assertActionHidden('cancel');
});

// --- Precondiciones del servicio reportadas como notificación, no como excepción cruda ---

it('shows a clear notification instead of a crash when the service rejects the cancellation', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin, [
        'header' => ['cash_amount' => 0, 'payment_term' => PaymentTermEnum::CREDIT->value],
    ]);

    $accountReceivable = $scenario['sale']->fresh()->accountReceivable;
    Payment::create([
        'model_type' => AccountReceivable::class,
        'model_id' => $accountReceivable->id,
        'amount' => 20,
        'balance_after_payment' => $accountReceivable->remaining_balance - 20,
        'payment_date' => now(),
        'payment_method' => 'cash',
    ]);

    $component = Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->callAction('cancel', data: ['reason' => 'Intento con pagos ya registrados']);

    // La acción no lanza: el servicio comunica el rechazo vía notificación,
    // la venta sigue activa.
    $component->assertHasNoActionErrors();
    expect($scenario['sale']->fresh()->status)->not->toBe(SaleStatusEnum::CANCELLED);
});

it('shows the initial cash_amount refund warning for a credit sale with an advance payment (R6)', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin, [
        'header' => ['cash_amount' => 40, 'payment_term' => PaymentTermEnum::CREDIT->value],
    ]);

    Livewire::test(ViewSale::class, ['record' => $scenario['sale']->fresh()->getKey()])
        ->mountAction('cancel')
        ->assertSee('40.00');
});

// --- Tabla de ventas: misma acción disponible ---

it('exposes the same cancel action on the sales table row', function () {
    $admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $admin->assignRole(Role::findOrCreate(UserRole::ADMIN->value, 'web'));
    grantSalePermissions($admin);

    $scenario = makeCancellableSale($admin);

    Livewire::test(\App\Filament\Resources\SaleResource\Pages\ListSales::class)
        ->assertTableActionVisible('cancel', $scenario['sale']->fresh());
});
