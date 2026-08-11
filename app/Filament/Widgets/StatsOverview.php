<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\AccountReceivable;
use App\Models\FinishedProductInventory;
use App\Models\RawMaterialsInventory;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 0;
    protected static bool $isLazy = true;
    public static function canView(): bool
    {
        $user = Auth::user();
        return UserRole::canUserViewWidget($user, static::class);
    }

    public function mount(): void
    {
        if (! UserRole::canUserViewWidget(Auth::user(), static::class)) {
            abort(403);
        }
    }
    
    protected function getStats(): array
    {
        $user = Auth::user();
        $isCashier = $user && $user->hasRole(UserRole::CASHEER->value); 

        // Productos con stock crítico (siempre disponible)
        $criticalStock = FinishedProductInventory::whereColumn('stock', '<=', 'min_stock')
            ->count();
            
        // Materia prima con stock crítico (siempre disponible)
        $criticalRawMaterialStock = RawMaterialsInventory::whereColumn('stock', '<=', 'min_stock')
            ->count();

        // Ventas del mes actual
        $currentMonthSales = Sale::notCancelled()
            ->whereMonth('sale_date', now()->month)
            ->whereYear('sale_date', now()->year)
            ->sum('total_amount');

        // Cuentas por cobrar vencidas
        $overdueAccounts = AccountReceivable::where('status', 'pending')
            ->where('due_date', '<', now())
            ->sum('remaining_balance');

        // Empleado destacado del mes
        // whereMonth/whereYear sobre sales.sale_date ya excluyen las filas NULL del
        // left join (empleados sin ventas), así que un where plano sobre status
        // es seguro aquí: no hay riesgo de perder empleados por NULL != 'cancelled'.
        $topEmployee = Employee::select('employees.first_name', 'employees.last_name')
            ->leftJoin('sales', 'employees.id', '=', 'sales.employee_id')
            ->whereMonth('sales.sale_date', now()->month)
            ->whereYear('sales.sale_date', now()->year)
            ->where('sales.status', '!=', Sale::cancelledStatusValue())
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name')
            ->orderBy(DB::raw('SUM(sales.total_amount)'), 'desc')
            ->first();

        $stats = [
            Stat::make('Ventas del Mes', 'L.' . number_format($currentMonthSales, 0))
                ->description('Ingresos del mes actual')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->extraAttributes([
                    'class' => $isCashier ? 'hidden' : '',
                ])
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make('Cuentas Vencidas', 'L.' . number_format($overdueAccounts, 0))
                ->description('Monto pendiente de cobro')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->extraAttributes([
                    'class' => $isCashier ? 'hidden' : '',
                ])
                ->color($overdueAccounts > 0 ? 'danger' : 'success'),

            Stat::make('Stock Crítico Productos', $criticalStock)
                ->description('Productos bajo mínimo')
                ->descriptionIcon('heroicon-m-archive-box-x-mark')
                ->color($criticalStock > 0 ? 'warning' : 'success'),

            Stat::make('Stock Crítico Insumos', $criticalRawMaterialStock)
                ->description('Materia prima bajo mínimo')
                ->descriptionIcon('heroicon-m-beaker')
                ->color($criticalRawMaterialStock > 0 ? 'danger' : 'success'),

            Stat::make('Empleado Destacado', $topEmployee ? $topEmployee->first_name . ' ' . $topEmployee->last_name : 'Sin datos')
                ->description('Mejor vendedor del mes')
                ->descriptionIcon('heroicon-m-trophy')
                ->extraAttributes([
                    'class' => $isCashier ? 'hidden' : '',
                ])
                ->color('info'),
        ];
        
        // Reordenar stats para mostrar stock al principio para cajeros si se desea, 
        // o mantener el orden pero ocultando los no permitidos.
        // En este caso, el orden es fijo pero los hidden no se muestran.

        return $stats;
    }
}
