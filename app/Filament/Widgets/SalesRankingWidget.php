<?php

namespace App\Filament\Widgets;

use App\Enums\SaleStatusEnum;
use App\Models\Employee;
use App\Models\Sale;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Enums\UserRole;

class SalesRankingWidget extends BaseWidget
{
    protected static ?string $heading = '🏆 Ranking de Vendedores';
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';
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
    
    public function table(Table $table): Table
    {
        return $table
            ->query(
                Employee::query()
                    ->select([
                        'employees.id',
                        'employees.first_name',
                        'employees.last_name',
                        'employees.created_at',
                        'employees.updated_at',
                        DB::raw('COUNT(sales.id) as total_sales'),
                        DB::raw('COALESCE(SUM(sales.total_amount), 0) as total_amount'),
                        DB::raw('COALESCE(SUM(CASE WHEN sales.payment_method = "cash" THEN sales.total_amount ELSE 0 END), 0) as cash_sales'),
                        DB::raw('COALESCE(SUM(CASE WHEN sales.payment_method = "deposit" THEN sales.total_amount ELSE 0 END), 0) as deposit_sales'),
                    ])
                    // Ventas anuladas fuera del ranking. Se filtra dentro del ON (no en
                    // un WHERE posterior) para no perder los empleados sin ventas: con
                    // left join, sales.status es NULL en esas filas y un WHERE normal
                    // las descartaría.
                    ->leftJoin('sales', function ($join) {
                        $join->on('employees.id', '=', 'sales.employee_id')
                            ->where(function ($query) {
                                $query->where('sales.status', '!=', SaleStatusEnum::CANCELLED->value)
                                    ->orWhereNull('sales.status');
                            });
                    })
                    ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.created_at', 'employees.updated_at')
                    ->orderBy('total_amount', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('ranking')
                    ->label('#')
                    ->getStateUsing(function ($record, $rowLoop) {
                        return $rowLoop->iteration;
                    })
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        1 => 'success',
                        2 => 'warning', 
                        3 => 'info',
                        default => 'gray'
                    })
                    ->icon(fn ($state) => match($state) {
                        1 => 'heroicon-o-trophy',
                        2 => 'heroicon-o-star',
                        3 => 'heroicon-o-heart',
                        default => null
                    }),
                    
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Empleado')
                    ->getStateUsing(fn ($record) => $record->first_name . ' ' . $record->last_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable()
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('total_sales')
                    ->label('Total Ventas')
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn ($state) => number_format($state, 0)),
                    
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Monto Total')
                    ->sortable()
                    ->money('HNL')
                    ->weight('bold')
                    ->color('success'),
                    
                Tables\Columns\TextColumn::make('cash_sales')
                    ->label('Ventas Efectivo')
                    ->sortable()
                    ->money('HNL')
                    ->color('warning'),
                    
                Tables\Columns\TextColumn::make('deposit_sales')
                    ->label('Ventas Depósito')
                    ->sortable()
                    ->money('HNL')
                    ->color('info'),
                    
                Tables\Columns\TextColumn::make('average_sale')
                    ->label('Promedio por Venta')
                    ->getStateUsing(function ($record) {
                        return $record->total_sales > 0 
                            ? $record->total_amount / $record->total_sales 
                            : 0;
                    })
                    ->money('HNL')
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\Filter::make('has_sales')
                    ->label('Solo con ventas')
                    ->query(fn (Builder $query): Builder => $query->having('total_sales', '>', 0))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\Action::make('view_details')
                    ->label('Ver Detalles')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->url(fn ($record) => route('filament.admin.resources.employees.view', $record)),
            ])
            ->emptyStateHeading('No hay datos de ventas')
            ->emptyStateDescription('Aún no se han registrado ventas para mostrar el ranking.')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->striped()
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
