<?php

namespace App\Filament\Actions;

use App\Enums\TypeInventoryManagementEnum;
use App\Models\FinishedProductInventory;
use App\Models\RawMaterialsInventory;
use App\Services\ManagementInventoryService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra un movimiento de inventario desde la propia fila de la tabla, sin
 * tener que entrar al registro y bajar hasta la pestaña de movimientos.
 *
 * Sirve para cualquier modelo de inventario con relación `movements()`
 * (producto terminado y materia prima), igual que
 * ManagementInventoryService::processMovement, que es quien hace el trabajo.
 */
class RegisterInventoryMovementAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'register_movement';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Movimiento')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->modalWidth(MaxWidth::Large)
            ->modalHeading('Registrar movimiento de inventario')
            ->modalDescription(fn (Model $record): string => static::describeRecord($record))
            ->modalSubmitActionLabel('Registrar')
            ->form(static::formSchema())
            ->action(function (Model $record, array $data): void {
                try {
                    app(ManagementInventoryService::class)->processMovement(
                        model: $record,
                        quantity: (float) $data['quantity'],
                        type: $data['type'],
                        description: $data['description'],
                    );

                    Notification::make()
                        ->title('Movimiento registrado')
                        ->body(static::describeRecord($record->refresh()))
                        ->success()
                        ->send();
                } catch (\RuntimeException $e) {
                    // Stock insuficiente: es un error de negocio, no una falla.
                    Notification::make()
                        ->title('No se pudo registrar el movimiento')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('type')
                    ->label('Tipo')
                    ->options(TypeInventoryManagementEnum::getOptions())
                    ->default(TypeInventoryManagementEnum::ENTRADA->value)
                    ->required()
                    ->native(false)
                    ->live(),

                Forms\Components\TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->required()
                    ->live(onBlur: true)
                    ->suffix(fn (?Model $record): ?string => $record ? static::unitLabel($record) : null)
                    // Las salidas y los dañados no pueden dejar el stock en
                    // negativo. El servicio también lo valida, pero avisar
                    // aquí evita el viaje al servidor para descubrirlo.
                    ->rule(function (?Model $record, Forms\Get $get) {
                        return function (string $attribute, $value, \Closure $fail) use ($record, $get) {
                            if (!$record || !static::reducesStock($get('type'))) {
                                return;
                            }

                            if ((float) $value > (float) $record->stock) {
                                $fail("La cantidad supera la existencia actual ({$record->stock}).");
                            }
                        };
                    }),
            ]),

            Forms\Components\Placeholder::make('resulting_stock')
                ->label('Existencia resultante')
                ->content(function (?Model $record, Forms\Get $get): string {
                    if (!$record) {
                        return '—';
                    }

                    $quantity = (float) ($get('quantity') ?? 0);
                    $type = $get('type');

                    if ($quantity <= 0 || !$type) {
                        return number_format((float) $record->stock, 2) . ' ' . static::unitLabel($record);
                    }

                    $resulting = static::reducesStock($type)
                        ? (float) $record->stock - $quantity
                        : (float) $record->stock + $quantity;

                    return number_format((float) $record->stock, 2)
                        . ' → ' . number_format($resulting, 2)
                        . ' ' . static::unitLabel($record);
                }),

            Forms\Components\Textarea::make('description')
                ->label('Descripción')
                ->required()
                ->maxLength(255)
                ->rows(2)
                ->placeholder('Motivo del movimiento'),
        ];
    }

    /**
     * Salida y dañado descuentan; entrada y devolución suman.
     */
    protected static function reducesStock(?string $type): bool
    {
        return in_array($type, [
            TypeInventoryManagementEnum::SALIDA->value,
            TypeInventoryManagementEnum::DANADO->value,
        ], true);
    }

    /**
     * La materia prima se mide en su propia unidad (libra, fardo…); el
     * producto terminado siempre en unidades.
     */
    protected static function unitLabel(Model $record): string
    {
        if ($record instanceof RawMaterialsInventory) {
            return (string) $record->unit_type;
        }

        return 'unidades';
    }

    protected static function describeRecord(Model $record): string
    {
        $name = match (true) {
            $record instanceof FinishedProductInventory => $record->product?->name ?? "Producto #{$record->product_id}",
            $record instanceof RawMaterialsInventory => $record->name,
            default => class_basename($record) . " #{$record->getKey()}",
        };

        $branch = $record->branch?->name;
        $stock = number_format((float) $record->stock, 2) . ' ' . static::unitLabel($record);

        return $branch
            ? "{$name} · {$branch} · existencia actual {$stock}"
            : "{$name} · existencia actual {$stock}";
    }
}
