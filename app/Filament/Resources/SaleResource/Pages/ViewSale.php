<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use Filament\Actions;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Split;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->modalHeading('Anular venta')
                ->modalSubmitActionLabel('Anular venta')
                ->form(fn () => SaleResource::cancelActionFormSchema())
                ->visible(fn (Sale $record) => SaleResource::saleCanBeCancelledByCurrentUser($record))
                ->action(function (Sale $record, array $data) {
                    SaleResource::handleCancelAction($record, $data);
                })
                // Recarga completa: el infolist lee del $record cargado al
                // montar la página, que no se actualiza solo tras la acción.
                ->after(fn () => redirect(static::getResource()::getUrl('view', ['record' => $this->record]))),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Grid::make()
                    ->columns(6)
                    ->schema([
                        Section::make('Información de la Venta')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('invoice_number')
                                            ->label('Número de Factura')
                                            ->placeholder('Sin número asignado')
                                            ->weight(FontWeight::Bold),

                                        TextEntry::make('sale_date')
                                            ->label('Fecha de Venta')
                                            ->date('d/m/Y')
                                            ->weight(FontWeight::Medium),

                                        TextEntry::make('client.full_name')
                                            ->label('Cliente')
                                            ->placeholder('Cliente General'),

                                        TextEntry::make('employee.full_name')
                                            ->label('Empleado')
                                            ->weight(FontWeight::Medium),

                                        TextEntry::make('status')
                                            ->label('Estado')
                                            ->badge()
                                            ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Estado')
                                            ->color(fn($state) => $state?->getColor() ?? 'gray'),

                                        TextEntry::make('payment_term')
                                            ->label('Término de Pago')
                                            ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Término')
                                            ->badge()
                                            ->color(fn($state) => $state?->getColor() ?? 'gray'),
                                            
                                        TextEntry::make('payment_method')
                                            ->label('Método de Pago')
                                            ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Método')
                                            ->badge()
                                            ->color(fn($state) => $state?->getColor() ?? 'gray'),
                                    ]),
                            ])->columnSpan(4),
                        
                        Section::make('Resumen Financiero')
                        ->schema([
                            TextEntry::make('subtotal')
                                ->label('Subtotal')
                                ->money('HNL')
                                ->weight(FontWeight::Medium),

                            TextEntry::make('discount_amount')
                                ->label('Descuento')
                                ->money('HNL')
                                ->visible(fn($record) => $record->discount_amount > 0),

                            TextEntry::make('total_amount')
                                ->label('Total')
                                ->money('HNL')
                                ->weight(FontWeight::Bold)
                                ->size('lg'),

                            TextEntry::make('cash_amount')
                                ->label('Monto Pagado')
                                ->money('HNL')
                                ->color(fn($record) => $record->cash_amount >= $record->total_amount ? 'success' : 'warning'),

                            TextEntry::make('balance')
                                ->label('Saldo Pendiente')
                                ->money('HNL')
                                ->state(fn($record) => max(0, $record->total_amount - $record->cash_amount))
                                ->color(fn($record) => $record->cash_amount >= $record->total_amount ? 'success' : 'danger')
                                ->visible(fn($record) => $record->cash_amount < $record->total_amount),
                        ])->columnSpan(2),
                    ]),


                Section::make('Productos Vendidos')
                    ->schema([
                        RepeatableEntry::make('details')
                            ->label('Detalles de Productos')
                            ->schema([
                                Grid::make(6)
                                    ->schema([
                                        TextEntry::make('product_name')
                                            ->getStateUsing(fn($record) => $record->product?->name . ' (' . $record->product?->code . ')')
                                            ->label('Producto')
                                            ->weight(FontWeight::Medium),

                                        TextEntry::make('quantity')
                                            ->label('Cantidad')
                                            ->numeric(decimalPlaces: 2)
                                            ->suffix(fn($record) => ' ' . $record->unit_abbreviation),

                                        TextEntry::make('unit_price_without_tax')
                                            ->label('Precio Unitario')
                                            ->money('HNL'),

                                        TextEntry::make('line_subtotal')
                                            ->label('Subtotal')
                                            ->money('HNL'),

                                        TextEntry::make('line_tax_amount')
                                            ->label('Impuesto')
                                            ->money('HNL'),

                                        TextEntry::make('line_total')
                                            ->label('Total')
                                            ->money('HNL')
                                            ->weight(FontWeight::Bold),
                                    ])
                            ])
                            ->contained(false)
                    ]),

                Group::make([
                    Section::make('Información Adicional')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextEntry::make('notes')
                                        ->label('Notas')
                                        ->placeholder('Sin notas adicionales')
                                        ->columnSpanFull(),

                                    TextEntry::make('payment_reference')
                                        ->label('Referencia de Pago')
                                        ->placeholder('Sin referencia')
                                        ->visible(fn($record) => !empty($record->payment_reference)),

                                    TextEntry::make('due_date')
                                        ->label('Fecha de Vencimiento')
                                        ->date('d/m/Y')
                                        ->visible(fn($record) => !empty($record->due_date)),
                                ])
                        ])
                        ->collapsible(),

                    Section::make('Auditoría')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextEntry::make('createdBy.name')
                                        ->label('Creado por'),

                                    TextEntry::make('created_at')
                                        ->label('Fecha de Creación')
                                        ->dateTime('d/m/Y H:i'),

                                    TextEntry::make('updatedBy.name')
                                        ->label('Actualizado por'),

                                    TextEntry::make('updated_at')
                                        ->label('Última Actualización')
                                        ->dateTime('d/m/Y H:i'),
                                ])
                        ])
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Anulación')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextEntry::make('cancelledBy.name')
                                        ->label('Anulada por'),

                                    TextEntry::make('cancelled_at')
                                        ->label('Fecha de anulación')
                                        ->dateTime('d/m/Y H:i'),

                                    TextEntry::make('cancellation_reason')
                                        ->label('Motivo')
                                        ->columnSpanFull(),
                                ])
                        ])
                        ->visible(fn (Sale $record) => $record->isCancelled()),
                ])
            ]);
    }
}
