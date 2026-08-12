<?php

namespace App\Filament\Resources;

use App\Filament\Actions\RegisterInventoryMovementAction;
use App\Filament\Resources\FinishedProductInventoryResource\Pages;
use App\Filament\Resources\ManagementInventoryResource\RelationManagers\MovementsInventoryRelationManager;
use App\Models\FinishedProductInventory;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FinishedProductInventoryResource extends Resource
{
    protected static ?string $model = FinishedProductInventory::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?string $navigationLabel = 'Inventario de productos terminados';

    protected static ?string $modelLabel = 'Inventario de productos terminados';

    protected static ?string $pluralModelLabel = 'Inventario de productos terminados';
    
    protected static ?string $singularModelLabel = 'Inventario de producto terminado';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make("Información del inventario")
                    ->description("Información del inventario de productos")
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('branch_id')
                            ->relationship('branch', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->label('Sucursal'),

                        Forms\Components\Select::make('product_id')
                            ->relationship('product', 'name')
                            ->required()
                            ->unique(
                                FinishedProductInventory::class,
                                modifyRuleUsing: fn($rule, $get) => $rule->where('branch_id', $get('branch_id')),
                                ignoreRecord: true,
                            )
                            ->validationMessages([
                                'unique' => 'El producto ya existe en esta sucursal.',
                            ])
                            ->searchable()
                            ->preload()
                            ->label('Producto'),

                        Forms\Components\TextInput::make('stock')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->disabled(fn($livewire) => $livewire instanceof Pages\EditFinishedProductInventory)
                            ->dehydrated(fn($livewire) => !($livewire instanceof Pages\EditFinishedProductInventory))
                            ->helperText(fn($livewire) => $livewire instanceof Pages\EditFinishedProductInventory ?
                                'El stock se gestiona mediante el kardex y no puede ser modificado directamente.' : null)
                            ->label('Existencia'),

                        Forms\Components\TextInput::make('min_stock')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->label('Existencia mínima'),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branch.name')
                    ->searchable()
                    ->sortable()
                    ->label('Sucursal'),
                Tables\Columns\TextColumn::make('product.name')
                    ->searchable()
                    ->sortable()
                    ->label('Producto'),
                Tables\Columns\TextColumn::make('stock')
                    ->numeric()
                    ->sortable()
                    ->label('Existencia'),
                Tables\Columns\TextColumn::make('min_stock')
                    ->numeric()
                    ->sortable()
                    ->label('Existencia mínima'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Creado'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Actualizado'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Sucursal'),
                Tables\Filters\SelectFilter::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Producto'),
            ])
            ->actions([
                RegisterInventoryMovementAction::make(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MovementsInventoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinishedProductInventories::route('/'),
            'create' => Pages\CreateFinishedProductInventory::route('/create'),
            'view' => Pages\ViewFinishedProductInventory::route('/{record}'),
            'edit' => Pages\EditFinishedProductInventory::route('/{record}/edit'),
        ];
    }
}
