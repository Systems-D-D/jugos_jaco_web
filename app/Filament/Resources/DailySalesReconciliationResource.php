<?php

namespace App\Filament\Resources;

use App\Enums\ReconciliationStatusEnum;
use App\Filament\Resources\DailySalesReconciliationResource\Pages;
use App\Models\DailySalesReconciliation;
use App\Models\Employee;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DailySalesReconciliationResource extends Resource
{
    protected static ?string $model = DailySalesReconciliation::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Finanzas';

    protected static ?string $navigationLabel = 'Cuadres Diarios';

    protected static ?string $label = 'Cuadre Diario';

    protected static ?string $pluralLabel = 'Cuadres Diarios';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información General')
                    ->description('Información básica de la reconciliación diaria')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('branch_id')
                                    ->label('Sucursal')
                                    ->relationship('branch', 'name')
                                    ->searchable()
                                    ->required(),

                                Forms\Components\Select::make('employee_id')
                                    ->label('Empleado')
                                    ->relationship('employee', 'name')
                                    ->searchable()
                                    ->required(),

                                Forms\Components\DatePicker::make('reconciliation_date')
                                    ->label('Fecha de Reconciliación')
                                    ->required()
                                    ->default(now())
                                    ->rules([
                                        function () {
                                            return function (string $attribute, $value, \Closure $fail) {
                                                $employeeId = request()->input('employee_id');
                                                $recordId = request()->route('record'); // For edit mode
                                                
                                                if ($employeeId && $value) {
                                                    $query = \App\Models\DailySalesReconciliation::where('employee_id', $employeeId)
                                                        ->whereDate('reconciliation_date', $value);
                                                    
                                                    // Exclude current record when editing
                                                    if ($recordId) {
                                                        $query->where('id', '!=', $recordId);
                                                    }
                                                    
                                                    if ($query->exists()) {
                                                        $fail('Ya existe un cuadre para este empleado en la fecha seleccionada.');
                                                    }
                                                }
                                            };
                                        },
                                    ]),
                            ]),
                    ]),

                Forms\Components\Section::make('Ventas del Día')
                    ->description('Resumen de las ventas realizadas')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('total_cash_sales')
                                    ->label('Ventas de Contado')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->required(),

                                Forms\Components\TextInput::make('total_credit_sales')
                                    ->label('Ventas de Crédito')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->required(),

                                Forms\Components\TextInput::make('total_sales')
                                    ->label('Total de Ventas')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                    ]),

                Forms\Components\Section::make('Movimientos de Efectivo')
                    ->description('Efectivo recibido, depósitos y cobros')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('total_cash_received')
                                    ->label('Efectivo Recibido')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->required(),

                                Forms\Components\TextInput::make('total_deposits')
                                    ->label('Depósitos')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->default(0),

                                Forms\Components\TextInput::make('total_collections')
                                    ->label('Cobros/Pagos')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->default(0),
                            ]),
                    ]),

                Forms\Components\Section::make('Reconciliación')
                    ->description('Cálculos y diferencias')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('total_cash_expected')
                                    ->label('Efectivo Esperado')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('cash_difference')
                                    ->label('Diferencia')
                                    ->numeric()
                                    ->prefix('L')
                                    ->step(0.01)
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Select::make('status')
                                    ->label('Estado')
                                    ->options([
                                        ReconciliationStatusEnum::PENDING->value => ReconciliationStatusEnum::PENDING->getLabel(),
                                        ReconciliationStatusEnum::COMPLETED->value => ReconciliationStatusEnum::COMPLETED->getLabel(),
                                        ReconciliationStatusEnum::WITH_DIFFERENCES->value => ReconciliationStatusEnum::WITH_DIFFERENCES->getLabel(),
                                    ])
                                    ->default(ReconciliationStatusEnum::PENDING)
                                    ->required(),
                            ]),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones adicionales...')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reconciliation_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_sales')
                    ->label('Total Ventas')
                    ->money('HNL')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('success'),

                Tables\Columns\TextColumn::make('total_deposits')
                    ->label('Total Depósitos')
                    ->money('HNL')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('info'),

                Tables\Columns\TextColumn::make('total_collections')
                    ->label('Total Cobros')
                    ->money('HNL')
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->color('primary'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => $state->getColor())
                    ->icon(fn ($state) => $state->getIcon()),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        ReconciliationStatusEnum::PENDING->value => ReconciliationStatusEnum::PENDING->getLabel(),
                        ReconciliationStatusEnum::COMPLETED->value => ReconciliationStatusEnum::COMPLETED->getLabel(),
                        ReconciliationStatusEnum::WITH_DIFFERENCES->value => ReconciliationStatusEnum::WITH_DIFFERENCES->getLabel(),
                    ]),

                Tables\Filters\Filter::make('reconciliation_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Desde'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('reconciliation_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('reconciliation_date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('edit_pending')
                    ->label('Editar')
                    ->icon('heroicon-m-pencil-square')
                    ->url(fn ($record) => route('filament.admin.resources.daily-sales-reconciliations.create', [
                        'employee_id' => $record->employee_id,
                        // Sin la fecha el cuadre se abría en el día de hoy.
                        'reconciliation_date' => $record->reconciliation_date->format('Y-m-d'),
                    ]))
                    ->visible(fn ($record) => $record->status === \App\Enums\ReconciliationStatusEnum::PENDING)
                    ->color('warning')
                    ->iconButton(),
                Tables\Actions\ViewAction::make()
                    ->iconButton(),
                Tables\Actions\DeleteAction::make()
                    ->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('reconciliation_date', 'desc');
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
            'index' => Pages\ListDailySalesReconciliations::route('/'),
            'create' => Pages\CreateDailySalesReconciliation::route('/create'),
            'view' => Pages\ViewDailySalesReconciliation::route('/{record}'),
            'edit' => Pages\EditDailySalesReconciliation::route('/{record}/edit'),
        ];
    }
}