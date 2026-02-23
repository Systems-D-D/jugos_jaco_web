<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\StoragePath;
use App\Models\ClientEquipment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ClientEquipmentRelationManager extends RelationManager
{
    protected static string $relationship = 'equipment';

    protected static ?string $title = 'Equipos Asignados';

    protected static ?string $modelLabel = 'Asignar Equipo';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $pluralModelLabel = 'Equipos Asignados';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre del equipo')
                    ->required()
                    ->maxLength(255),
                Forms\Components\FileUpload::make('image_path')
                    ->label('Fotografía')
                    ->image()
                    ->required()
                    ->disk('public')
                    ->directory(StoragePath::CLIENTS_EQUIPMENT_IMAGES->value)
                    ->visibility('public')
                    ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file): string {
                        $uniqueId = uniqid();
                        $extension = $file->getClientOriginalExtension();
                        return "{$uniqueId}.{$extension}";
                    })
                    ->afterStateHydrated(function (Forms\Components\FileUpload $component) {
                        $record = $component->getRecord();

                        if ($record && $record->image) {
                            $component->state($record->image->path);
                        }
                    }),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\ImageColumn::make('equipmentImageUrl')
                        ->label('')
                        ->height('100%')
                        ->width('100%')
                        ->visibility('public'),
                    Tables\Columns\TextColumn::make('name')
                        ->label('Nombre del equipo')
                        ->weight('bold')
                        ->alignCenter(),
                ])->space(3),
            ])
            ->contentGrid([
                'md' => 3,
                'xl' => 5,
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Asignar equipo')
                    ->modalHeading('Asignar equipo')
                    ->using(function (array $data, string $model): Model {
                        $imagePath = $data['image_path'] ?? null;
                        unset($data['image_path']);
                        
                        if ($imagePath) {
                            $data['name'] = pathinfo($imagePath, PATHINFO_FILENAME);
                        } else {
                            $data['name'] = uniqid() . '_' . $this->getOwnerRecord()->id;
                        }
                        
                        /** @var ClientEquipment $record */
                        $record = $this->getRelationship()->create($data);
                        
                        if ($imagePath) {
                            $record->image()->create([
                                'path' => $imagePath,
                                'type' => 'equipment',
                            ]);
                        }
                        
                        return $record;
                    }),
            ])
            ->actions([
               Tables\Actions\DeleteAction::make()
                    ->after(function (Model $record) {
                        if ($record->image) {
                            if (Storage::disk('public')->exists($record->image->path)) {
                                Storage::disk('public')->delete($record->image->path);
                            }
                            $record->image()->delete();
                        }
                    }),
            ])
            ->bulkActions([
            ]);
    }
}
