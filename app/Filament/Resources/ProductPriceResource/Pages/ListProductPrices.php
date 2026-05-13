<?php

namespace App\Filament\Resources\ProductPriceResource\Pages;

use App\Filament\Resources\ProductPriceResource;
use App\Models\Category;
use App\Models\ProductPrice;
use App\Models\Unit;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProductPrices extends ListRecords
{
    protected static string $resource = ProductPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Action::make('bulkPriceUpdate')
                ->label('Actualizar Precios Masivamente')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->modalHeading('Actualización Masiva de Precios')
                ->modalDescription('Actualiza todos los precios que coincidan con los filtros seleccionados. Solo se modificarán productos activos.')
                ->form(function (Form $form) {
                    return $form->schema([
                        Select::make('type_price_id')
                            ->label('Tipo de Precio (Escala)')
                            ->relationship('typePrice', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->placeholder('Seleccione un tipo de precio'),

                        Select::make('category_id')
                            ->label('Categoría de Producto')
                            ->options(Category::pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->placeholder('Seleccione una categoría')
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('unit_id', null)),

                        Select::make('unit_id')
                            ->label('Unidad de Medida')
                            ->options(function (callable $get) {
                                $categoryId = $get('category_id');
                                if (! $categoryId) {
                                    return [];
                                }

                                return Unit::whereHas('productUnits.product', function ($query) use ($categoryId) {
                                    $query->where('category_id', $categoryId)
                                        ->where('is_active', true);
                                })
                                    ->active()
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->placeholder('Seleccione una unidad')
                            ->helperText('Solo se muestran unidades disponibles en la categoría seleccionada.'),

                        TextInput::make('price')
                            ->label('Nuevo Precio')
                            ->required()
                            ->numeric()
                            ->prefix('L.')
                            ->minValue(0.01)
                            ->maxValue(99999.99)
                            ->step(0.01)
                            ->helperText('Se actualizarán todos los precios que coincidan con los filtros.'),
                    ]);
                })
                ->action(function (array $data) {
                    $updated = ProductPrice::where('type_price_id', $data['type_price_id'])
                        ->whereHas('productUnit', fn ($query) => $query->where('unit_id', $data['unit_id']))
                        ->whereHas('product', function ($query) use ($data) {
                            $query->where('category_id', $data['category_id'])
                                ->where('is_active', true);
                        })
                        ->update(['price' => $data['price']]);

                    if ($updated > 0) {
                        Notification::make()
                            ->title('¡Actualización completada!')
                            ->body("{$updated} precio(s) actualizado(s) exitosamente.")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Sin coincidencias')
                            ->body('No se encontraron precios con los filtros seleccionados. Verifica Tipo de Precio, Categoría y Unidad.')
                            ->warning()
                            ->send();
                    }
                })
                ->modalFooterActionsAlignment('end')
                ->modalSubmitActionLabel('Actualizar Precios'),
        ];
    }
}
