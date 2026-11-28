<?php

use App\Constants\PermissionConstants;
use App\Enums\AccountReceivableStatusEnum;
use App\Filament\Resources\AccountReceivableResource;
use App\Filament\Resources\AccountReceivableResource\Pages\ListAccountReceivables;
use App\Models\AccountReceivable;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Lista de cuentas por cobrar: la columna Cliente muestra "negocio (nombre)",
 * se busca y ordena por ese texto, y el clic en la fila abre el detalle.
 */
function arListUser(array $permissions): User
{
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);

    // La página consulta todos los permisos del recurso (crear, borrar...):
    // deben existir aunque el usuario no los tenga.
    foreach ([
        PermissionConstants::ACCOUNT_RECEIVABLE_CREATE,
        PermissionConstants::ACCOUNT_RECEIVABLE_VIEW,
        PermissionConstants::ACCOUNT_RECEIVABLE_UPDATE,
        PermissionConstants::ACCOUNT_RECEIVABLE_DELETE,
        PermissionConstants::ACCOUNT_RECEIVABLE_LIST,
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user->givePermissionTo($permissions);

    return $user;
}

function arForClient(array $clientAttributes, string $name = 'Cuenta'): AccountReceivable
{
    $client = Client::factory()->create($clientAttributes);
    $sale = Sale::factory()->create(['client_id' => $client->id, 'payment_term' => 'credit']);

    return arWithoutSale($name, ['sales_id' => $sale->id]);
}

function arWithoutSale(string $name, array $attributes = []): AccountReceivable
{
    return AccountReceivable::create(array_merge([
        'name' => $name,
        'total_amount' => 100,
        'remaining_balance' => 100,
        'status' => AccountReceivableStatusEnum::PENDING,
    ], $attributes));
}

beforeEach(function () {
    $this->actingAs(arListUser([
        PermissionConstants::ACCOUNT_RECEIVABLE_LIST,
        PermissionConstants::ACCOUNT_RECEIVABLE_VIEW,
        PermissionConstants::ACCOUNT_RECEIVABLE_UPDATE,
    ]));
});

it('shows the business name with the client name, or the account name without a sale', function () {
    $withBusiness = arForClient(['business_name' => 'Pulpería La Esperanza', 'first_name' => 'María', 'last_name' => 'López']);
    $withoutBusiness = arForClient(['business_name' => '', 'first_name' => 'José', 'last_name' => 'Martínez']);
    $manual = arWithoutSale('Préstamo a Carlos');

    Livewire::test(ListAccountReceivables::class)
        ->assertTableColumnStateSet('sale.client.business_name', 'Pulpería La Esperanza (María López)', $withBusiness)
        ->assertTableColumnStateSet('sale.client.business_name', 'José Martínez', $withoutBusiness)
        ->assertTableColumnStateSet('sale.client.business_name', 'Préstamo a Carlos', $manual);
});

it('searches by business name, client name and account name', function () {
    $esperanza = arForClient(['business_name' => 'Pulpería La Esperanza', 'first_name' => 'María', 'last_name' => 'López']);
    $sabor = arForClient(['business_name' => 'Cafetería El Buen Sabor', 'first_name' => 'José', 'last_name' => 'Martínez']);
    $manual = arWithoutSale('Préstamo a Carlos');

    Livewire::test(ListAccountReceivables::class)
        ->searchTable('Esperanza')
        ->assertCanSeeTableRecords([$esperanza])
        ->assertCanNotSeeTableRecords([$sabor, $manual])
        ->searchTable('María López')
        ->assertCanSeeTableRecords([$esperanza])
        ->assertCanNotSeeTableRecords([$sabor, $manual])
        ->searchTable('Carlos')
        ->assertCanSeeTableRecords([$manual])
        ->assertCanNotSeeTableRecords([$esperanza, $sabor]);
});

it('sorts by the displayed client text, including accounts without a sale', function () {
    $zelaya = arWithoutSale('Zelaya');
    $sol = arForClient(['business_name' => 'Abarrotería Sol', 'first_name' => 'Ana', 'last_name' => 'Ruiz']);
    $mario = arForClient(['business_name' => '', 'first_name' => 'Mario', 'last_name' => 'Paz']);

    Livewire::test(ListAccountReceivables::class)
        ->sortTable('sale.client.business_name')
        ->assertCanSeeTableRecords([$sol, $mario, $zelaya], inOrder: true)
        ->sortTable('sale.client.business_name', 'desc')
        ->assertCanSeeTableRecords([$zelaya, $mario, $sol], inOrder: true);
});

it('opens the detail page when clicking a row, not the edit page', function () {
    $account = arWithoutSale('Cuenta');

    Livewire::test(ListAccountReceivables::class)
        ->assertTableActionDoesNotExist('view')
        ->assertSeeHtml(AccountReceivableResource::getUrl('view', ['record' => $account]));

    $table = Livewire::test(ListAccountReceivables::class)->instance()->getTable();

    expect($table->getRecordUrl($account))
        ->toBe(AccountReceivableResource::getUrl('view', ['record' => $account]));
});

it('falls back to the edit page when the user cannot view the account', function () {
    $this->actingAs(arListUser([
        PermissionConstants::ACCOUNT_RECEIVABLE_LIST,
        PermissionConstants::ACCOUNT_RECEIVABLE_UPDATE,
    ]));
    $account = arWithoutSale('Cuenta');

    $table = Livewire::test(ListAccountReceivables::class)->instance()->getTable();

    expect($table->getRecordUrl($account))
        ->toBe(AccountReceivableResource::getUrl('edit', ['record' => $account]));
});
