<?php

namespace App\Filament\Resources;

use App\Enums\UnitTypeEnum;
use App\Filament\Actions\RegisterInventoryMovementAction;
use App\Filament\Resources\ManagementInventoryResource\RelationManagers\MovementsInventoryRelationManager;
use App\Filament\Resources\RawMaterialsInventoryResource\Pages;
use App\Models\RawMaterialsInventory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables;

class RawMaterialsInventoryResource extends Resource
{
    protected static ?string $model = RawMaterialsInventory::class;

    protected static ?string $navigationGroup = 'Inventario';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('unit_type')
                    ->label('Unidad')
                    ->options(UnitTypeEnum::getValues())
                    ->required(),
                Forms\Components\TextInput::make('stock')
                    ->label('Cantidad')
                    ->numeric()
                    ->disabled(fn($livewire) => $livewire instanceof Pages\EditRawMaterialsInventory)
                    ->required(),
                Forms\Components\TextInput::make('min_stock')
                    ->label('Stock Mínimo')
                    ->numeric()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock')
                    ->label('Existencia')
                    ->formatStateUsing(fn($record): string => "{$record->stock} {$record->unit_type}")
                    ->sortable(),
                Tables\Columns\TextColumn::make('min_stock')
                    ->label('Stock Mínimo')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Última Actualización')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                //
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
            'index' => Pages\ListRawMaterialsInventories::route('/'),
            'create' => Pages\CreateRawMaterialsInventory::route('/create'),
            'view' => Pages\ViewRawMaterialsInventory::route('/{record}'),
            'edit' => Pages\EditRawMaterialsInventory::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Materia Prima';
    }

    public static function getModelLabel(): string
    {
        return 'Materia Prima';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Materias Primas';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-beaker';
    }

    public static function getNavigationSort(): int
    {
        return 2;
    }
}
