<?php

namespace App\Filament\Resources\AccountReceivableResource\Widgets;

use App\Enums\AccountReceivableStatusEnum;
use App\Models\AccountReceivable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountReceivableStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        // Las CxC canceladas (venta anulada, sin pagos) no son parte de la
        // cartera activa: quedan fuera de todos los totales para que un
        // puñado de anulaciones no infle "Monto Total" ni desinfle el
        // porcentaje de cobranza con dinero que nunca se iba a cobrar.
        $totalReceivables = AccountReceivable::notCancelled()->count();
        $pendingReceivables = AccountReceivable::where('status', AccountReceivableStatusEnum::PENDING)->count();
        $paidReceivables = AccountReceivable::where('status', AccountReceivableStatusEnum::PAID)->count();
        $overdueReceivables = AccountReceivable::where('due_date', '<', now())
            ->where('status', AccountReceivableStatusEnum::PENDING)
            ->count();

        $totalAmount = AccountReceivable::notCancelled()->sum('total_amount');
        $totalPending = AccountReceivable::where('status', AccountReceivableStatusEnum::PENDING)->sum('remaining_balance');
        $totalPaid = $totalAmount - AccountReceivable::notCancelled()->sum('remaining_balance');

        return [
            Stat::make('Total Cuentas por Cobrar', $totalReceivables)
                ->description('Total de cuentas registradas')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('gray'),

            Stat::make('Cuentas Pendientes', $pendingReceivables)
                ->description('Cuentas por cobrar pendientes')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Cuentas Pagadas', $paidReceivables)
                ->description('Cuentas completamente pagadas')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Cuentas Vencidas', $overdueReceivables)
                ->description('Cuentas vencidas pendientes')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            Stat::make('Monto Total', 'L. ' . number_format($totalAmount, 2))
                ->description('Valor total de cuentas por cobrar')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('gray'),

            Stat::make('Saldo Pendiente', 'L. ' . number_format($totalPending, 2))
                ->description('Total por cobrar pendiente')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make('Total Cobrado', 'L. ' . number_format($totalPaid, 2))
                ->description('Total cobrado hasta la fecha')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Porcentaje de Cobranza', number_format($totalAmount > 0 ? ($totalPaid / $totalAmount) * 100 : 0, 1) . '%')
                ->description('Eficiencia de cobranza')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($totalAmount > 0 && ($totalPaid / $totalAmount) > 0.8 ? 'success' : 'warning'),
        ];
    }
}
