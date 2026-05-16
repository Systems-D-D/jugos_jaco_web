<?php

namespace App\Filament\Resources\DailySalesReconciliationResource\Pages;

use App\Filament\Resources\DailySalesReconciliationResource;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;
use App\Models\Bill;
use App\Models\ProductReturn;

class ViewDailySalesReconciliation extends ViewRecord
{
    protected static string $resource = DailySalesReconciliationResource::class;

    // INFO: To prevent N+1 queries from inline aggregates (bills()->sum, deposits()->count, etc.),
    // consider adding eager loading in DailySalesReconciliationResource::getEloquentQuery():
    // ->with(['bills', 'deposits', 'productReturns', 'typePrice', 'cashier', 'branch', 'employee'])
    // Or use withCount(['bills', 'deposits', 'productReturns']) and withSum(['bills as total_bills_sum' => 'amount'])

    protected function getHeaderActions(): array
    {
        return [
            // Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // === TASK 1: BARRA DE ESTADO COMPACTA ===
                Section::make('Informacion del Cuadre')
                    ->schema([
                        Grid::make(6)
                            ->schema([
                                Group::make([
                                    TextEntry::make('reconciliation_date')
                                        ->label('Fecha')
                                        ->date('d/m/Y')
                                        ->weight(FontWeight::Bold)
                                        ->size('lg'),
                                ])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('branch.name')
                                        ->label('Sucursal')
                                        ->weight(FontWeight::Bold)
                                        ->size('lg')
                                        ->color('info'),
                                ])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('employee.full_name')
                                        ->label('Empleado')
                                        ->weight(FontWeight::Bold)
                                        ->size('lg'),
                                ])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('cashier.name')
                                        ->label('Cajero')
                                        ->weight(FontWeight::Medium)
                                        ->size('md')
                                        ->placeholder('No asignado'),
                                ])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('typePrice.name')
                                        ->label('Escala')
                                        ->weight(FontWeight::Medium)
                                        ->size('md')
                                        ->placeholder('No asignada')
                                        ->hidden(fn ($record) => !$record->type_price_id),
                                ])->columnSpan(1),

                                Group::make([
                                    TextEntry::make('status')
                                        ->label('Estado')
                                        ->formatStateUsing(function ($state) {
                                            return $state->getLabel();
                                        })
                                        ->badge()
                                        ->size('lg')
                                        ->color(fn ($state) => $state->getColor())
                                        ->icon(fn ($state) => $state->getIcon()),
                                ])->columnSpan(1),
                            ]),
                    ])
                    ->compact()
                    ->extraAttributes(['class' => 'border-b border-gray-100 rounded-none shadow-none']),

                // === TASK 2: GRID PRINCIPAL 2 COLUMNAS + SIDEBAR ===
                Grid::make(3)
                    ->schema([
                        // COLUMNA IZQUIERDA (2/3 del ancho)
                        Group::make([
                            // TASK 3: Ventas del Dia
                            Section::make('Ventas del Dia')
                                ->description('Desglose de ventas por tipo')
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            Group::make([
                                                TextEntry::make('cash_sales')
                                                    ->label('Efectivo')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->color('success')
                                                    ->size('lg'),
                                                TextEntry::make('deposit_sales')
                                                    ->label('Deposito')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Medium)
                                                    ->color('info')
                                                    ->size('md'),
                                                TextEntry::make('total_cash_sales')
                                                    ->label('Subtotal Contado')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->extraAttributes(['class' => 'border-t-2 border-gray-100 pt-2 mt-2']),
                                            ])->extraAttributes(['class' => 'space-y-2']),

                                            Group::make([
                                                TextEntry::make('total_credit_sales')
                                                    ->label('Ventas Credito')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->color('warning')
                                                    ->size('lg'),
                                                TextEntry::make('cash_collections')
                                                    ->label('Cobros Efectivo')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Medium)
                                                    ->color('success')
                                                    ->size('md'),
                                                TextEntry::make('deposit_collections')
                                                    ->label('Cobros Deposito')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Medium)
                                                    ->color('info')
                                                    ->size('md'),
                                                TextEntry::make('total_collections')
                                                    ->label('Subtotal Cobros')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->extraAttributes(['class' => 'border-t-2 border-gray-100 pt-2 mt-2']),
                                            ])->extraAttributes(['class' => 'space-y-2']),
                                        ]),

                                    TextEntry::make('total_sales')
                                        ->label('Total Ventas')
                                        ->money('HNL')
                                        ->weight(FontWeight::Bold)
                                        ->size('xl')
                                        ->color('primary')
                                        ->extraAttributes(['class' => 'border-t-2 border-gray-200 pt-3 mt-3 text-center']),
                                ])
                                ->compact(),

                            // TASK 4: Movimientos de Efectivo
                            Section::make('Movimientos de Efectivo')
                                ->description('Flujo del dia')
                                ->schema([
                                    Grid::make(4)
                                        ->schema([
                                            Group::make([
                                                TextEntry::make('total_cash_received')
                                                    ->label('Efectivo Recibido')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('xl')
                                                    ->color('success'),
                                            ])->extraAttributes(['class' => 'bg-green-50 rounded-lg p-3 text-center']),

                                            Group::make([
                                                TextEntry::make('total_deposits')
                                                    ->label('Depositos')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('xl')
                                                    ->color('info'),
                                            ])->extraAttributes(['class' => 'bg-blue-50 rounded-lg p-3 text-center']),

                                            Group::make([
                                                TextEntry::make('total_collections')
                                                    ->label('Cobros')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('xl')
                                                    ->color('purple'),
                                            ])->extraAttributes(['class' => 'bg-purple-50 rounded-lg p-3 text-center']),

                                            Group::make([
                                                TextEntry::make('total_bills')
                                                    ->label('Gastos')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('xl')
                                                    ->color('danger')
                                                    ->state(fn ($record) => $record->bills()->sum('amount')),
                                            ])->extraAttributes(['class' => 'bg-red-50 rounded-lg p-3 text-center']),
                                        ]),
                                ])
                                ->compact(),

                            // TASK 5: Depositos
                            Section::make('Depositos Registrados')
                                ->schema([
                                    RepeatableEntry::make('deposits')
                                        ->hiddenLabel()
                                        ->schema([
                                            Grid::make(4)
                                                ->schema([
                                                    TextEntry::make('bank')
                                                        ->label('Banco')
                                                        ->formatStateUsing(fn ($state) => is_string($state) ? $state : ($state?->getLabel() ?? ''))
                                                        ->badge()
                                                        ->color(fn ($state) => is_string($state) ? 'gray' : ($state?->getColor() ?? 'gray')),
                                                    TextEntry::make('reference_number')
                                                        ->label('Referencia')
                                                        ->weight(FontWeight::Medium)
                                                        ->copyable()
                                                        ->copyMessage('Referencia copiada')
                                                        ->extraAttributes(['class' => 'font-mono text-sm']),
                                                    TextEntry::make('amount')
                                                        ->label('Monto')
                                                        ->money('HNL')
                                                        ->weight(FontWeight::Bold)
                                                        ->color('success')
                                                        ->extraAttributes(['class' => 'text-right']),
                                                    TextEntry::make('created_at')
                                                        ->label('Hora')
                                                        ->dateTime('H:i')
                                                        ->size('sm')
                                                        ->color('gray'),
                                                ]),
                                        ])
                                        ->columns(1)
                                        ->contained(false)
                                        ->state(function ($record) {
                                            $deposits = $record->deposits()
                                                ->orderBy('created_at', 'desc')
                                                ->get()
                                                ->map(function ($deposit) {
                                                    return [
                                                        'bank' => $deposit->bank,
                                                        'reference_number' => $deposit->reference_number,
                                                        'amount' => $deposit->amount,
                                                        'created_at' => $deposit->created_at,
                                                    ];
                                                })
                                                ->toArray();
                                            
                                            return empty($deposits) ? [[
                                                'bank' => '',
                                                'reference_number' => 'No hay depositos registrados',
                                                'amount' => 0,
                                                'created_at' => null,
                                            ]] : $deposits;
                                        }),
                                ])
                                ->compact()
                                ->collapsible(),

                            // TASK 5: Gastos
                            Section::make('Gastos del Dia')
                                ->schema([
                                    RepeatableEntry::make('bills')
                                        ->hiddenLabel()
                                        ->schema([
                                            Grid::make(3)
                                                ->schema([
                                                    TextEntry::make('description')
                                                        ->label('Descripcion')
                                                        ->weight(FontWeight::Medium),
                                                    TextEntry::make('reference_number')
                                                        ->label('Referencia')
                                                        ->weight(FontWeight::Medium)
                                                        ->copyable()
                                                        ->copyMessage('Referencia copiada')
                                                        ->extraAttributes(['class' => 'font-mono text-sm']),
                                                    TextEntry::make('amount')
                                                        ->label('Monto')
                                                        ->money('HNL')
                                                        ->weight(FontWeight::Bold)
                                                        ->color('danger')
                                                        ->extraAttributes(['class' => 'text-right']),
                                                ]),
                                        ])
                                        ->columns(1)
                                        ->contained(false)
                                        ->state(function ($record) {
                                            $bills = $record->bills()
                                                ->orderBy('created_at', 'desc')
                                                ->get()
                                                ->map(function ($bill) {
                                                    return [
                                                        'description' => $bill->description,
                                                        'reference_number' => $bill->reference_number,
                                                        'amount' => $bill->amount,
                                                    ];
                                                })
                                                ->toArray();
                                            
                                            return empty($bills) ? [[
                                                'description' => 'No hay gastos registrados',
                                                'reference_number' => '',
                                                'amount' => 0,
                                            ]] : $bills;
                                        }),
                                    
                                    TextEntry::make('total_bills')
                                        ->label('Total Gastos')
                                        ->money('HNL')
                                        ->weight(FontWeight::Bold)
                                        ->size('lg')
                                        ->color('danger')
                                        ->state(fn ($record) => $record->bills()->sum('amount'))
                                        ->extraAttributes(['class' => 'text-right border-t-2 border-gray-200 pt-2 mt-2']),
                                ])
                                ->compact()
                                ->collapsible(),

                            // TASK 6: Devoluciones
                            Section::make('Devoluciones de Productos')
                                ->schema([
                                    RepeatableEntry::make('productReturns')
                                        ->hiddenLabel()
                                        ->schema([
                                            Grid::make(4)
                                                ->schema([
                                                    TextEntry::make('product_id')
                                                        ->label('Producto')
                                                        ->weight(FontWeight::Bold)
                                                        ->state(fn ($record) => $record->product->name ?? 'Producto no encontrado'),
                                                    TextEntry::make('quantity')
                                                        ->label('Cant.')
                                                        ->weight(FontWeight::Medium)
                                                        ->extraAttributes(['class' => 'text-center']),
                                                    TextEntry::make('type')
                                                        ->label('Tipo')
                                                        ->badge()
                                                        ->color(fn ($state) => match($state?->value ?? $state) {
                                                            'damaged' => 'danger',
                                                            'returned' => 'warning',
                                                            default => 'gray'
                                                        })
                                                        ->formatStateUsing(fn ($state) => match($state?->value ?? $state) {
                                                            'damaged' => 'Danado',
                                                            'returned' => 'Retornado',
                                                            default => is_string($state) ? $state : ($state?->value ?? '')
                                                        }),
                                                    TextEntry::make('reason')
                                                        ->label('Motivo')
                                                        ->weight(FontWeight::Medium)
                                                        ->limit(40),
                                                ]),
                                        ])
                                        ->columns(1)
                                        ->contained(false),
                                    
                                    Grid::make(3)
                                        ->schema([
                                            Group::make([
                                                TextEntry::make('total_damaged')
                                                    ->label('Danados')
                                                    ->suffix(' unidades')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->color('danger')
                                                    ->state(fn ($record) => $record->productReturns()->where('type', 'damaged')->sum('quantity')),
                                            ])->extraAttributes(['class' => 'bg-red-50 rounded-lg p-3 text-center']),

                                            Group::make([
                                                TextEntry::make('total_returned')
                                                    ->label('Retornados')
                                                    ->suffix(' unidades')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->color('warning')
                                                    ->state(fn ($record) => $record->productReturns()->where('type', 'returned')->sum('quantity')),
                                            ])->extraAttributes(['class' => 'bg-amber-50 rounded-lg p-3 text-center']),

                                            Group::make([
                                                TextEntry::make('product_shortage_total')
                                                    ->label('Costo Faltante')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->color('warning')
                                                    ->placeholder('L 0.00'),
                                            ])->extraAttributes(['class' => 'bg-amber-50 rounded-lg p-3 text-center']),
                                        ])
                                        ->extraAttributes(['class' => 'mt-3']),
                                ])
                                ->compact()
                                ->collapsible(),
                        ])->columnSpan(2),

                        // COLUMNA DERECHA - SIDEBAR (1/3 del ancho)
                        Group::make([
                            // TASK 7: Diferencias
                            Section::make('Resultado del Cuadre')
                                ->schema([
                                    Group::make([
                                        TextEntry::make('cash_difference')
                                            ->label('Efectivo')
                                            ->weight(FontWeight::Bold)
                                            ->size('lg')
                                            ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                                            ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '') . 'L ' . number_format($state, 2))
                                            ->extraAttributes(['class' => 'text-right']),
                                        TextEntry::make('cash_difference_description')
                                            ->label('')
                                            ->state(fn ($record) => 'Esperado L ' . number_format($record->total_cash_expected, 2) . ' · Recibido L ' . number_format($record->total_cash_received, 2))
                                            ->size('sm')
                                            ->color('gray'),
                                    ])->extraAttributes(fn ($record) => [
                                        'class' => $record->cash_difference > 0 
                                            ? 'bg-green-50 rounded-lg p-3 flex items-center gap-3 mb-2' 
                                            : ($record->cash_difference < 0 
                                                ? 'bg-red-50 rounded-lg p-3 flex items-center gap-3 mb-2' 
                                                : 'bg-gray-50 rounded-lg p-3 flex items-center gap-3 mb-2'),
                                    ]),

                                    Group::make([
                                        TextEntry::make('deposit_difference')
                                            ->label('Depositos')
                                            ->weight(FontWeight::Bold)
                                            ->size('lg')
                                            ->color(fn ($state) => $state == 0 ? 'info' : ($state > 0 ? 'success' : 'danger'))
                                            ->formatStateUsing(fn ($state) => 'L ' . number_format($state, 2))
                                            ->extraAttributes(['class' => 'text-right']),
                                        TextEntry::make('deposit_difference_description')
                                            ->label('')
                                            ->state(fn ($record) => 'Esperado L ' . number_format($record->total_deposit_expected, 2) . ' · Realizado L ' . number_format($record->total_deposits, 2))
                                            ->size('sm')
                                            ->color('gray'),
                                    ])->extraAttributes(fn ($record) => [
                                        'class' => $record->deposit_difference == 0 
                                            ? 'bg-gray-50 rounded-lg p-3 flex items-center gap-3 mb-2' 
                                            : ($record->deposit_difference > 0 
                                                ? 'bg-green-50 rounded-lg p-3 flex items-center gap-3 mb-2' 
                                                : 'bg-amber-50 rounded-lg p-3 flex items-center gap-3 mb-2'),
                                    ]),

                                    Group::make([
                                        TextEntry::make('product_shortage_total')
                                            ->label('Prod. Faltante')
                                            ->weight(FontWeight::Bold)
                                            ->size('lg')
                                            ->color('warning')
                                            ->money('HNL')
                                            ->placeholder('L 0.00')
                                            ->extraAttributes(['class' => 'text-right']),
                                        TextEntry::make('product_shortage_description')
                                            ->label('')
                                            ->state(fn ($record) => ($record->typePrice?->name ?? 'Sin escala') . ' · Requiere revision')
                                            ->size('sm')
                                            ->color('gray')
                                            ->hidden(fn ($record) => !$record->product_shortage_total),
                                    ])->extraAttributes(['class' => 'bg-amber-50 rounded-lg p-3 flex items-center gap-3'])
                                        ->hidden(fn ($record) => !$record->product_shortage_total),
                                ])
                                ->compact(),

                            // TASK 8: Resumen Rapido
                            Section::make('Resumen Rapido')
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            Group::make([
                                                TextEntry::make('net_day')
                                                    ->label('Neto Dia')
                                                    ->money('HNL')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->state(fn ($record) => $record->total_cash_received - $record->total_deposits - $record->bills()->sum('amount')),
                                            ])->extraAttributes(['class' => 'bg-gray-50 rounded-lg p-2 text-center']),

                                            Group::make([
                                                TextEntry::make('typePrice.name')
                                                    ->label('Escala')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->placeholder('N/A'),
                                            ])->extraAttributes(['class' => 'bg-gray-50 rounded-lg p-2 text-center']),

                                            Group::make([
                                                TextEntry::make('deposits_count')
                                                    ->label('Depositos')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->state(fn ($record) => $record->deposits()->count()),
                                            ])->extraAttributes(['class' => 'bg-gray-50 rounded-lg p-2 text-center']),

                                            Group::make([
                                                TextEntry::make('bills_count')
                                                    ->label('Gastos')
                                                    ->weight(FontWeight::Bold)
                                                    ->size('lg')
                                                    ->state(fn ($record) => $record->bills()->count()),
                                            ])->extraAttributes(['class' => 'bg-gray-50 rounded-lg p-2 text-center']),
                                        ]),
                                ])
                                ->compact(),

                            // TASK 8: Observaciones
                            Section::make('Observaciones')
                                ->schema([
                                    TextEntry::make('notes')
                                        ->label('')
                                        ->placeholder('Sin observaciones registradas')
                                        ->extraAttributes(['class' => 'bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm']),
                                ])
                                ->compact(),

                            // TASK 8: Auditoria
                            Section::make('Auditoria')
                                ->schema([
                                    Grid::make(1)
                                        ->schema([
                                            Group::make([
                                                TextEntry::make('cashier.name')
                                                    ->label('Creado por')
                                                    ->weight(FontWeight::Medium)
                                                    ->size('sm'),
                                            ])->extraAttributes(['class' => 'flex justify-between items-center bg-gray-50 rounded-lg p-2']),

                                            Group::make([
                                                TextEntry::make('created_at')
                                                    ->label('Creacion')
                                                    ->dateTime('d/m/y H:i')
                                                    ->weight(FontWeight::Medium)
                                                    ->size('sm'),
                                            ])->extraAttributes(['class' => 'flex justify-between items-center bg-gray-50 rounded-lg p-2']),

                                            Group::make([
                                                TextEntry::make('updated_at')
                                                    ->label('Modificacion')
                                                    ->dateTime('d/m/y H:i')
                                                    ->weight(FontWeight::Medium)
                                                    ->size('sm'),
                                            ])->extraAttributes(['class' => 'flex justify-between items-center bg-gray-50 rounded-lg p-2']),
                                        ]),
                                ])
                                ->compact(),
                        ])->columnSpan(1),
                    ])
                    ->extraAttributes(['class' => 'gap-4']),
            ]);
    }
}
