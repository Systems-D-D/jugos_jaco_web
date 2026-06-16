<?php

namespace App\Filament\Resources\AssignedProductResource\RelationManagers;

use App\Enums\TypeInventoryManagementEnum;
use App\Filament\Support\FilamentNotification;
use App\Models\DetailAssignedProduct;
use App\Models\FinishedProductInventory;
use App\Services\ManagementInventoryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Filament\Tables\Actions\Action;


class DetailsRelationManager extends RelationManager
{
    protected static string $relationship = 'details';

    protected static ?string $recordTitleAttribute = 'product.name';

    protected static ?string $modelLabel = 'Producto Asignado';

    protected static ?string $title = 'Productos Asignados';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Producto'),
                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->default(1)
                    ->label('Cantidad'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto'),
                Tables\Columns\TextColumn::make('product.content_type')
                    ->label('Tipo de Contenido'),
                Tables\Columns\TextColumn::make('product.content')
                    ->label('Contenido'),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad asignada'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data): Model {
                        return DB::transaction(function () use ($data) {
                            $inventory = FinishedProductInventory::where([
                                'product_id' => $data['product_id'],
                                'branch_id' => $this->getOwnerRecord()->employee->branch_id,
                            ])->lockForUpdate()->first();

                            if (!$inventory) {
                                FilamentNotification::error(
                                    "No se encontró el inventario para este producto."
                                );
                                throw ValidationException::withMessages([
                                    'product_id' => "No se encontró el inventario para este producto.",
                                ]);
                            }

                            $existing = DetailAssignedProduct::where([
                                'assigned_products_id' => $this->getOwnerRecord()->id,
                                'product_id' => $data['product_id'],
                            ])->first();

                            if ($existing) {
                                FilamentNotification::error(
                                    'El producto ya está asignado en esta asignación. Edite la cantidad existente en lugar de duplicarlo.'
                                );
                                throw ValidationException::withMessages([
                                    'product_id' => 'Este producto ya está asignado en la asignación actual.',
                                ]);
                            }

                            if ($data['quantity'] > $inventory->stock) {
                                FilamentNotification::error(
                                    "No hay suficiente stock para asignar {$data['quantity']} productos."
                                );
                                throw ValidationException::withMessages([
                                    'quantity' => "No hay suficiente stock para asignar {$data['quantity']} productos.",
                                ]);
                            }

                            $record = DetailAssignedProduct::create([
                                'assigned_products_id' => $this->getOwnerRecord()->id,
                                'product_id' => $data['product_id'],
                                'quantity' => $data['quantity'],
                                'sale_quantity' => 0,
                                'returned_quantity' => 0,
                                'changes_quantity' => 0,
                                'royalties_quantity' => 0,
                            ]);

                            $this->managementInventoryCreateDetail($record);

                            return $record;
                        });
                    })
                    ->label('Agregar Productos Asignados')
                    ->visible(fn () => $this->disabledForPastOrFutureDates())
                    ->modalHeading('Asignar producto al empleado'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->before(function (Model $record) {
                        if ($record->sale_quantity > 0) {
                            FilamentNotification::error(
                                "No se puede eliminar el producto asignado porque ya se han vendido {$record->sale_quantity} productos."
                            );
                            throw ValidationException::withMessages([
                                'record' => "No se puede eliminar el producto asignado porque ya se han vendido {$record->sale_quantity} productos.",
                            ]);
                        }

                        $this->managementInventoryDeleteDetail($record);
                    })
                    ->iconButton()
                    ->visible(fn () => $this->disabledForPastOrFutureDates()),
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->visible(fn () => $this->disabledForPastOrFutureDates())
                    ->mutateRecordDataUsing(function (array $data, Model $record): array {
                        $data['original_quantity'] = $record->quantity;
                        return $data;
                    })
                    ->using(function (Model $record, array $data): Model {
                        return DB::transaction(function () use ($record, $data) {
                            $originalQuantity = $data['original_quantity'] ?? $record->quantity;

                            $salesQuantity = $record->sale_quantity ?? 0;
                            if ($data['quantity'] < $salesQuantity) {
                                FilamentNotification::error(
                                    "No se puede reducir la cantidad por debajo de {$salesQuantity} productos que ya han sido vendidos."
                                );

                                return $record;
                            }

                            unset($data['original_quantity']);
                            $record->update($data);

                            $this->managementInventoryUpdateDetail($record, $originalQuantity);

                            return $record;
                        });
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (\Illuminate\Database\Eloquent\Collection $records) {
                            DB::transaction(function () use ($records) {
                                foreach ($records as $record) {
                                    if ($record->sale_quantity > 0) {
                                        FilamentNotification::error(
                                            "No se puede eliminar {$record->product->name} porque ya se han vendido {$record->sale_quantity} productos."
                                        );
                                        throw ValidationException::withMessages([
                                            'record' => "No se puede eliminar {$record->product->name} porque ya tiene ventas registradas.",
                                        ]);
                                    }
                                    $this->managementInventoryDeleteDetail($record);
                                }
                            });
                        })
                        ->visible(fn () => $this->disabledForPastOrFutureDates()),
                ]),
            ]);
    }

    private function disabledForPastOrFutureDates(): bool
    {
        $assignedProduct = $this->getOwnerRecord();
        return $assignedProduct->date->format('Y-m-d') === now()->format('Y-m-d');
    }

    private function managementInventoryCreateDetail(DetailAssignedProduct $record): void
    {
        $product = FinishedProductInventory::where([
            'product_id' => $record->product_id,
            'branch_id' => $record->assignedProduct->employee->branch_id,
        ])->first();

        app(ManagementInventoryService::class)->processMovement(
            model: $product,
            quantity: $record->quantity,
            type: TypeInventoryManagementEnum::SALIDA->value,
            description: 'Asignación de producto al empleado: ' . $record->assignedProduct->employee->first_name . ' ' . $record->assignedProduct->employee->last_name,
            referenceId: $record->assignedProduct->id,
        );
    }

    private function managementInventoryDeleteDetail(DetailAssignedProduct $record): void
    {
        $product = FinishedProductInventory::where([
            'product_id' => $record->product_id,
            'branch_id' => $record->assignedProduct->employee->branch_id,
        ])->first();

        app(ManagementInventoryService::class)->processMovement(
            model: $product,
            quantity: $record->quantity,
            type: TypeInventoryManagementEnum::ENTRADA->value,
            description: 'Eliminación de producto asignado al empleado: ' . $record->assignedProduct->employee->first_name . ' ' . $record->assignedProduct->employee->last_name,
            referenceId: $record->assignedProduct->id,
        );
    }

    private function managementInventoryUpdateDetail(DetailAssignedProduct $record, int $originalQuantity): void
    {
        $product = FinishedProductInventory::where([
            'product_id' => $record->product_id,
            'branch_id' => $record->assignedProduct->employee->branch_id,
        ])->first();

        if (!$product) {
            Log::error('No se encontró el inventario del producto para actualizar', [
                'product_id' => $record->product_id,
                'branch_id' => $record->assignedProduct->employee->branch_id,
            ]);
            return;
        }

        $difference = $originalQuantity - $record->quantity;

        if ($difference > 0) {
            app(ManagementInventoryService::class)->processMovement(
                model: $product,
                quantity: abs($difference),
                type: TypeInventoryManagementEnum::ENTRADA->value,
                description: 'Ajuste de producto asignado al empleado: ' . $record->assignedProduct->employee->first_name . ' ' . $record->assignedProduct->employee->last_name,
                referenceId: $record->assignedProduct->id,
            );
        } elseif ($difference < 0) {
            app(ManagementInventoryService::class)->processMovement(
                model: $product,
                quantity: abs($difference),
                type: TypeInventoryManagementEnum::SALIDA->value,
                description: 'Ajuste de producto asignado al empleado: ' . $record->assignedProduct->employee->first_name . ' ' . $record->assignedProduct->employee->last_name,
                referenceId: $record->assignedProduct->id,
            );
        }
    }
}
