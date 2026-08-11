<?php

use App\Enums\AccountReceivableStatusEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use App\Enums\TypeInventoryManagementEnum;
use App\Filament\Resources\AccountReceivableResource\Widgets\AccountReceivableStatsOverview;
use App\Filament\Resources\ManagementInventoryResource;
use App\Models\AccountReceivable;
use App\Models\AssignedProduct;
use App\Models\Branch;
use App\Models\Client;
use App\Models\DetailAssignedProduct;
use App\Models\Employee;
use App\Models\ManagementInventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TypePrice;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Regresión de tres hallazgos de la revisión del PR #129
 * (docs/devflow/specs/2026-08-10-sale-deletion-analysis.md), fuera de
 * SaleCancellationService/PaymentService (ya cubiertos en
 * PaymentAccountReceivableRaceTest.php):
 *
 *  #4 — AccountReceivableStatsOverview no excluía CxC canceladas de sus totales.
 *  #5 — ManagementInventoryResource permitía borrar evidencia de anulación.
 *  #6 — StatsOverview/SalesRankingWidget duplicaban a mano el criterio "cancelada".
 */

function invokeGetStats(object $widget): array
{
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    return $method->invoke($widget);
}

function statValue(array $stats, string $label): mixed
{
    foreach ($stats as $stat) {
        $reflection = new ReflectionClass($stat);
        $property = $reflection->getProperty('label');
        $property->setAccessible(true);

        if ($property->getValue($stat) === $label) {
            $valueProperty = $reflection->getProperty('value');
            $valueProperty->setAccessible(true);

            return $valueProperty->getValue($stat);
        }
    }

    throw new RuntimeException("Stat '{$label}' no encontrado.");
}

// --- #4: AccountReceivableStatsOverview excluye CxC canceladas ---

it('excludes cancelled accounts receivable from the CxC dashboard totals', function () {
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();
    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'payment_term' => PaymentTermEnum::CREDIT,
    ]);

    AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC activa',
        'total_amount' => 100,
        'remaining_balance' => 60,
        'status' => AccountReceivableStatusEnum::PENDING,
    ]);
    AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC cancelada',
        'total_amount' => 9999,
        'remaining_balance' => 9999,
        'status' => AccountReceivableStatusEnum::CANCELLED,
    ]);

    $stats = invokeGetStats(new AccountReceivableStatsOverview());

    expect(statValue($stats, 'Total Cuentas por Cobrar'))->toBe(1)
        ->and(statValue($stats, 'Monto Total'))->toBe('L. 100.00');
});

it('does not let a cancelled account receivable distort the collection percentage', function () {
    $employee = Employee::factory()->create();
    $client = Client::factory()->create();
    $sale = Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'payment_term' => PaymentTermEnum::CREDIT,
    ]);

    // Totalmente pagada.
    AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC pagada',
        'total_amount' => 200,
        'remaining_balance' => 0,
        'status' => AccountReceivableStatusEnum::PAID,
    ]);
    // Cancelada: nunca se cobró nada, no debería aparecer ni en el
    // numerador ni en el denominador del porcentaje.
    AccountReceivable::create([
        'sales_id' => $sale->id,
        'name' => 'CxC cancelada',
        'total_amount' => 300,
        'remaining_balance' => 300,
        'status' => AccountReceivableStatusEnum::CANCELLED,
    ]);

    $stats = invokeGetStats(new AccountReceivableStatsOverview());

    // Sin la CxC cancelada en el denominador: 200/200 = 100%.
    expect(statValue($stats, 'Porcentaje de Cobranza'))->toBe('100.0%');
});

// --- #5: ManagementInventoryResource ya no permite borrado masivo ---

it('management inventory table no longer offers a bulk delete action', function () {
    $widget = new class extends \Filament\Widgets\TableWidget {
        public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
        {
            return ManagementInventoryResource::table($table);
        }
    };

    $table = $widget->table(\Filament\Tables\Table::make($widget));

    expect($table->getBulkActions())->toBeEmpty();
});

// --- #6: el criterio de "cancelada" en queries crudas viene de un único lugar ---

it('Sale::cancelledStatusValue matches the enum used by scopeNotCancelled', function () {
    expect(Sale::cancelledStatusValue())->toBe(SaleStatusEnum::CANCELLED->value);
});

it('sales ranking widget still excludes cancelled sales after reusing Sale::cancelledStatusValue()', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $client = Client::factory()->create();

    Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'status' => SaleStatusEnum::PAID,
        'total_amount' => 100,
        'payment_method' => 'cash',
    ]);
    Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'status' => SaleStatusEnum::CANCELLED,
        'total_amount' => 9999,
        'payment_method' => 'cash',
    ]);

    $widget = new \App\Filament\Widgets\SalesRankingWidget();
    $table = $widget->table(\Filament\Tables\Table::make($widget));

    $row = $table->getQuery()->where('employees.id', $employee->id)->first();

    expect((float) $row->total_amount)->toBe(100.0);
});

it('stats overview top employee query still excludes cancelled sales after reusing Sale::cancelledStatusValue()', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $client = Client::factory()->create();

    Sale::factory()->create([
        'employee_id' => $employee->id,
        'client_id' => $client->id,
        'sale_date' => now(),
        'status' => SaleStatusEnum::CANCELLED,
        'total_amount' => 9999,
        'payment_method' => 'cash',
    ]);

    $stats = invokeGetStats(new \App\Filament\Widgets\StatsOverview());

    // Sin ninguna venta no-cancelada este mes, "Empleado Destacado" no debe
    // resolver al empleado cuya única venta está anulada.
    expect(statValue($stats, 'Empleado Destacado'))->toBe('Sin datos');
});
