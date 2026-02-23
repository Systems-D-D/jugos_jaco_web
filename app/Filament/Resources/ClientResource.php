<?php

namespace App\Filament\Resources;

use App\Enums\DepartmentEnum;
use App\Enums\MunicipalityEnum;
use App\Enums\VisitDayEnum;
use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use App\Models\Employee;
use App\Models\TypePrice;
use Filament\Tables\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Storage;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationGroup = 'Clientes';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del cliente')
                    ->description('En esta sección se registra la información del cliente.')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('first_name')
                                    ->label('Nombres')
                                    ->required()
                                    ->maxLength(50),
                                Forms\Components\TextInput::make('last_name')
                                    ->label('Apellidos')
                                    ->required()
                                    ->maxLength(50),
                                Forms\Components\TextInput::make('phone_number')
                                    ->label('Teléfono')
                                    ->tel()
                                    ->required()
                                    ->maxLength(15),
                                Forms\Components\TextInput::make('business_name')
                                    ->label('Nombre del negocio')
                                    ->required()
                                    ->maxLength(50)
                            ]),
                        Forms\Components\Grid::make(1)
                            ->schema([
                                Forms\Components\TextInput::make('address')
                                    ->label('Dirección')
                                    ->required()
                                    ->maxLength(120),
                            ]),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('department')
                                    ->label('Departamento')
                                    ->searchable()
                                    ->options(DepartmentEnum::toArray())
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $set('municipality', null);
                                    })
                                    ->required(),
                                Forms\Components\Select::make('township')
                                    ->label('Municipio')
                                    ->options(function (callable $get) {
                                        $department = $get('department');
                                        if (!$department) {
                                            return [];
                                        }
                                        return collect(MunicipalityEnum::getByDepartment(DepartmentEnum::from($department)))
                                            ->mapWithKeys(fn($municipality) => [$municipality => $municipality]);
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ]),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('employee_id')
                                    ->label('Empleado asignado')
                                    ->relationship('employee')
                                    ->getOptionLabelFromRecordUsing(fn($record) => $record->full_name)
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                Forms\Components\Select::make('type_price_id')
                                    ->label('Tipo de Precio')
                                    ->options(TypePrice::query()->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nombre')
                                            ->required(),
                                    ])
                                    ->createOptionUsing(fn(array $data) => TypePrice::create($data)->id)
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('profileImageUrl')
                    ->defaultImageUrl(asset('/images/avatar.png'))
                    ->label('')
                    ->size(30)
                    ->circular()
                    ->action(
                        Action::make('preview')
                            ->label('Foto del cliente')
                            ->modalHeading('Foto del cliente')
                            ->modalSubmitAction(false) // Remove submit button
                            ->modalCancelAction(false) // Remove cancel button
                            ->modalContent(fn($record) => view('filament.components.client-image-modal', [
                                'imageUrl' => $record->profileImage?->path
                                    ? Storage::url($record->profileImage->path)
                                    : asset('/images/avatar.png')
                            ]))
                            ->modalWidth('md')
                    ),
                TextColumn::make('full_name')
                    ->label('Encargado')
                    ->searchable(['clients.first_name', 'clients.last_name'])
                    ->sortable(query: function ($query, $direction) {
                        return $query
                            ->orderBy('clients.first_name', $direction)
                            ->orderBy('clients.last_name', $direction);
                    }),
                TextColumn::make('business_name')
                    ->label('Negocio')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->label('Teléfono')
                    ->searchable('clients.phone_number'),
                TextColumn::make('employee.full_name')
                    ->label('Empleado asignado')
                    ->sortable(query: function ($query, $direction) {
                        return $query
                            ->join('employees', 'clients.employee_id', '=', 'employees.id')
                            ->orderBy('employees.first_name', $direction)
                            ->orderBy('employees.last_name', $direction)
                            ->select('clients.*');
                    }),
                TextColumn::make('typePrice.name')
                    ->label('Tipo de precio')
                    ->sortable(),
                TextColumn::make('department')
                    ->label('Departamento')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('township')
                    ->label('Municipio')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address')
                    ->label('Dirección')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Fecha de creación')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Fecha de edición')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'id')
                    ->getOptionLabelFromRecordUsing(fn($record) => $record->full_name)
                    ->searchable()
                    ->preload()
                    ->multiple(),
            ])
            ->actions([
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
            ClientResource\RelationManagers\ClientEquipmentRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'view' => Pages\ViewClient::route('/{record}'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Clientes';
    }

    public static function getModelLabel(): string
    {
        return 'Cliente';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Clientes';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    public static function getNavigationSort(): int
    {
        return 1;
    }

    public static function create(array $data)
    {
        // Guardar el cliente primero sin latitude y longitude
        $client = Client::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone_number' => $data['phone_number'],
            'business_name' => $data['business_name'],
            'position' => $data['position'],
            'visit_day' => $data['visit_day'],
            'address' => $data['address'],
            'department' => $data['department'],
            'township' => $data['township'],
            'employee_id' => $data['employee_id'],
            'type_price_id' => $data['type_price_id'],
        ]);

        // Guardar la ubicación polimórficamente
        $client->location()->create([
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'model_id' => $client->id,
            'model' => Client::class,
        ]);

        return $client;
    }
}