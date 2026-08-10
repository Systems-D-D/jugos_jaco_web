<?php

namespace App\Filament\Resources;

use App\Enums\PaymentTypeEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use App\Filament\Resources\SaleResource\Pages;
use App\Models\Employee;
use App\Models\Sale;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('sale_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('client.full_name')
                    ->label('Cliente')
                    ->searchable(['clients.first_name', 'clients.last_name'])
                    ->placeholder('Cliente General'),
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(['employees.first_name', 'employees.last_name']),
                TextColumn::make('details_count')
                    ->counts('details')
                    ->label('Productos')
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('payment_term')
                    ->label('Término de Pago')
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Término')
                    ->color(fn($state) => $state?->getColor() ?? '')
                    ->badge(),
                TextColumn::make('payment_method')
                    ->label('Método de Pago')
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Método')
                    ->color(fn($state) => $state?->getColor() ?? '')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Estado')
                    ->color(fn($state) => $state?->getColor() ?? '')
                    ->badge()
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->options(Employee::get()->pluck('full_name', 'id')),
                Tables\Filters\SelectFilter::make('payment_term')
                    ->label('Término de Pago')
                    ->options(PaymentTermEnum::getOptions()),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Método de Pago')
                    ->options(PaymentTypeEnum::getOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(collect(SaleStatusEnum::cases())->mapWithKeys(fn($case) => [$case->value => $case->getLabel()])->toArray()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('desde')
                            ->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when(
                                $data['desde'],
                                fn ($query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('sale_date', '>=', $date),
                            )
                            ->when(
                                $data['hasta'],
                                fn ($query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('sale_date', '<=', $date),
                            );
                    })
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
            // DeleteBulkAction retirado: una venta nunca se borra físicamente, se
            // anula (ver docs/devflow/specs/2026-08-10-sale-deletion-analysis.md).
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'view' => Pages\ViewSale::route('/{record}'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }
}
