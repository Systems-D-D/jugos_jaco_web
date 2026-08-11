<?php

namespace App\Filament\Pages;

use App\Constants\PermissionConstants;
use App\Enums\TypeInventoryManagementEnum;
use App\Models\Branch;
use App\Models\FinishedProductInventory;
use App\Models\RawMaterialsInventory;
use App\Services\ManagementInventoryService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Carga de varios movimientos de inventario en una sola pasada.
 *
 * El tipo y la descripción se eligen una vez para todo el lote; cada línea
 * aporta su producto y cantidad. Todo el trabajo real lo sigue haciendo
 * ManagementInventoryService::processMovement — esta página sólo arma el
 * lote y lo envuelve en una transacción.
 */
class RegisterInventoryMovements extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'Inventario';
    protected static ?string $navigationLabel = 'Registro de movimientos';
    protected static ?string $title = 'Registro de movimientos';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.register-inventory-movements';

    public const TYPE_FINISHED = 'finished';
    public const TYPE_RAW = 'raw';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'inventory_type' => self::TYPE_FINISHED,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
            'lines' => [[]],
        ]);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user
            && $user->hasAnyPermission([
                PermissionConstants::FINISHED_PRODUCT_INVENTORY_UPDATE,
                PermissionConstants::RAW_MATERIALS_INVENTORY_UPDATE,
            ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos del lote')
                    ->description('Se aplican a todos los productos que agregue abajo.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('inventory_type')
                            ->label('Inventario')
                            ->options([
                                self::TYPE_FINISHED => 'Producto terminado',
                                self::TYPE_RAW => 'Materia prima',
                            ])
                            ->required()
                            ->native(false)
                            ->live()
                            // Cambiar de inventario invalida las líneas ya
                            // cargadas: son ids de otra tabla.
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('lines', [[]])),

                        Forms\Components\Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('lines', [[]])),

                        Forms\Components\Select::make('type')
                            ->label('Tipo de movimiento')
                            ->options(TypeInventoryManagementEnum::getOptions())
                            ->required()
                            ->native(false)
                            ->live(),

                        Forms\Components\TextInput::make('description')
                            ->label('Descripción')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Motivo del movimiento'),
                    ]),

                Forms\Components\Repeater::make('lines')
                    ->label('Productos')
                    ->addActionLabel('Agregar producto')
                    ->columns(12)
                    ->minItems(1)
                    ->reorderable(false)
                    ->schema([
                        Forms\Components\Select::make('inventory_id')
                            ->label('Producto')
                            ->options(fn (Get $get) => static::inventoryOptions(
                                $get('../../inventory_type'),
                                $get('../../branch_id'),
                            ))
                            ->searchable()
                            ->preload()
                            ->required()
                            // Evita cargar dos líneas del mismo producto: se
                            // sumarían por separado y el usuario no lo vería.
                            ->distinct()
                            ->live()
                            ->columnSpan(7)
                            ->placeholder(fn (Get $get) => $get('../../branch_id')
                                ? 'Escriba para buscar…'
                                : 'Elija primero una sucursal'),

                        Forms\Components\TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpan(2),

                        Forms\Components\Placeholder::make('resulting')
                            ->label('Existencia')
                            ->columnSpan(3)
                            ->content(function (Get $get): string {
                                $record = static::resolveInventory(
                                    $get('../../inventory_type'),
                                    $get('inventory_id'),
                                );

                                if (!$record) {
                                    return '—';
                                }

                                $unit = static::unitLabel($record);
                                $current = (float) $record->stock;
                                $quantity = (float) ($get('quantity') ?? 0);

                                if ($quantity <= 0) {
                                    return number_format($current, 2) . ' ' . $unit;
                                }

                                $resulting = static::reducesStock($get('../../type'))
                                    ? $current - $quantity
                                    : $current + $quantity;

                                return number_format($current, 2)
                                    . ' → ' . number_format($resulting, 2)
                                    . ' ' . $unit;
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function register(): void
    {
        $data = $this->form->getState();

        $inventoryType = $data['inventory_type'];
        $movementType = $data['type'];
        $description = $data['description'];
        $lines = $data['lines'] ?? [];

        if (empty($lines)) {
            Notification::make()
                ->title('Agregue al menos un producto')
                ->warning()
                ->send();

            return;
        }

        $service = app(ManagementInventoryService::class);

        try {
            // Todo o nada: si una línea no tiene existencia suficiente se
            // cae el lote completo, en vez de dejar registrados los
            // anteriores y que nadie sepa cuáles pasaron.
            DB::transaction(function () use ($lines, $inventoryType, $movementType, $description, $service) {
                foreach ($lines as $line) {
                    $record = static::resolveInventory($inventoryType, $line['inventory_id']);

                    if (!$record) {
                        throw new \RuntimeException(
                            'Uno de los productos seleccionados ya no existe. Actualice la página e intente de nuevo.'
                        );
                    }

                    try {
                        $service->processMovement(
                            model: $record,
                            quantity: (float) $line['quantity'],
                            type: $movementType,
                            description: $description,
                        );
                    } catch (\RuntimeException $e) {
                        // Se renombra el error para que diga QUÉ producto falló:
                        // el servicio sólo sabe de cantidades, no de nombres.
                        throw new \RuntimeException(
                            static::describeRecord($record) . ' — ' . $e->getMessage()
                        );
                    }
                }
            });
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('No se registró ningún movimiento')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $count = count($lines);

        Notification::make()
            ->title($count === 1 ? 'Movimiento registrado' : "{$count} movimientos registrados")
            ->body(TypeInventoryManagementEnum::from($movementType)->getLabel() . ' · ' . $description)
            ->success()
            ->send();

        // Se conservan tipo, sucursal y descripción para encadenar otro lote.
        $this->form->fill([
            'inventory_type' => $inventoryType,
            'branch_id' => $data['branch_id'] ?? null,
            'type' => $movementType,
            'description' => $description,
            'lines' => [[]],
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    protected static function inventoryOptions(?string $inventoryType, $branchId): array
    {
        if (!$branchId) {
            return [];
        }

        if ($inventoryType === self::TYPE_RAW) {
            return RawMaterialsInventory::where('branch_id', $branchId)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (RawMaterialsInventory $item) => [
                    $item->id => "{$item->name} — {$item->stock} {$item->unit_type}",
                ])
                ->all();
        }

        return FinishedProductInventory::with('product')
            ->where('branch_id', $branchId)
            ->get()
            ->sortBy(fn (FinishedProductInventory $item) => $item->product?->name ?? '')
            ->mapWithKeys(fn (FinishedProductInventory $item) => [
                $item->id => ($item->product?->name ?? "Producto #{$item->product_id}")
                    . " — {$item->stock} en existencia",
            ])
            ->all();
    }

    protected static function resolveInventory(?string $inventoryType, $id): ?Model
    {
        if (!$id) {
            return null;
        }

        return $inventoryType === self::TYPE_RAW
            ? RawMaterialsInventory::find($id)
            : FinishedProductInventory::with('product')->find($id);
    }

    protected static function reducesStock(?string $type): bool
    {
        return in_array($type, [
            TypeInventoryManagementEnum::SALIDA->value,
            TypeInventoryManagementEnum::DANADO->value,
        ], true);
    }

    protected static function unitLabel(Model $record): string
    {
        return $record instanceof RawMaterialsInventory
            ? (string) $record->unit_type
            : 'unidades';
    }

    protected static function describeRecord(Model $record): string
    {
        return $record instanceof RawMaterialsInventory
            ? $record->name
            : ($record->product?->name ?? "Producto #{$record->product_id}");
    }
}
