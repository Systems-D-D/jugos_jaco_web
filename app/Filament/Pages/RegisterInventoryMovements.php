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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Carga de varios movimientos de inventario en una sola pasada.
 *
 * El tipo y la descripción se eligen una vez para todo el lote; cada línea
 * aporta su producto y cantidad. Todo el trabajo real lo sigue haciendo
 * ManagementInventoryService::processMovement — esta página sólo arma el
 * lote y lo envuelve en una transacción.
 *
 * Las líneas viven fuera del formulario de Filament (en $lines, como estado
 * plano de Livewire) para poder pintarlas como tabla: el Repeater de
 * Filament 3 sólo sabe apilar tarjetas, y con quince productos eso es una
 * pantalla imposible de revisar de un vistazo.
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

    /** Cabecera del lote (formulario de Filament). */
    public ?array $data = [];

    /** @var array<int, array{inventory_id: int|string, quantity: string|null}> */
    public array $lines = [];

    public string $search = '';

    public function mount(): void
    {
        $this->form->fill([
            'inventory_type' => self::TYPE_FINISHED,
            'type' => TypeInventoryManagementEnum::ENTRADA->value,
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
                    ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
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
                            ->afterStateUpdated(fn () => $this->resetLines()),

                        Forms\Components\Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetLines()),

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
            ])
            ->statePath('data');
    }

    public function resetLines(): void
    {
        $this->lines = [];
        $this->search = '';
        $this->resetErrorBag();
    }

    /**
     * Productos que coinciden con la búsqueda y todavía no están en el lote.
     *
     * @return Collection<int, array{id: int, label: string, stock: string}>
     */
    public function suggestions(): Collection
    {
        $branchId = $this->data['branch_id'] ?? null;

        if (!$branchId) {
            return collect();
        }

        $alreadyAdded = collect($this->lines)->pluck('inventory_id')->all();
        $term = trim($this->search);

        return $this->availableInventories($branchId)
            ->reject(fn (array $item) => in_array($item['id'], $alreadyAdded))
            ->when($term !== '', fn (Collection $items) => $items->filter(
                fn (array $item) => str_contains(
                    mb_strtolower($item['label']),
                    mb_strtolower($term)
                )
            ))
            ->take(8)
            ->values();
    }

    public function addLine(int $inventoryId): void
    {
        // El mismo producto dos veces se sumaría por separado y el usuario
        // no lo vería venir; se ignora en silencio porque el buscador ya
        // excluye los que están cargados.
        if (collect($this->lines)->contains('inventory_id', $inventoryId)) {
            return;
        }

        $this->lines[] = ['inventory_id' => $inventoryId, 'quantity' => null];
        $this->search = '';
    }

    /** Enter en el buscador agrega el primer resultado, sin tocar el mouse. */
    public function addFirstSuggestion(): void
    {
        $first = $this->suggestions()->first();

        if ($first) {
            $this->addLine($first['id']);
        }
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->resetErrorBag();
    }

    /**
     * Las líneas resueltas contra la base, con existencia actual y resultante.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lineRows(): Collection
    {
        $inventoryType = $this->data['inventory_type'] ?? self::TYPE_FINISHED;
        $movementType = $this->data['type'] ?? null;

        return collect($this->lines)->map(function (array $line, int $index) use ($inventoryType, $movementType) {
            $record = static::resolveInventory($inventoryType, $line['inventory_id']);

            if (!$record) {
                return [
                    'index' => $index,
                    'name' => 'Producto no disponible',
                    'unit' => '',
                    'current' => null,
                    'resulting' => null,
                    'quantity' => $line['quantity'] ?? null,
                ];
            }

            $current = (float) $record->stock;
            $quantity = (float) ($line['quantity'] ?? 0);
            $resulting = null;

            if ($quantity > 0 && $movementType) {
                $resulting = static::reducesStock($movementType)
                    ? $current - $quantity
                    : $current + $quantity;
            }

            return [
                'index' => $index,
                'name' => static::describeRecord($record),
                'unit' => static::unitLabel($record),
                'current' => $current,
                'resulting' => $resulting,
                'quantity' => $line['quantity'] ?? null,
            ];
        });
    }

    /** @return array{products: int, units: float} */
    public function totals(): array
    {
        return [
            'products' => count($this->lines),
            'units' => (float) collect($this->lines)->sum(fn (array $line) => (float) ($line['quantity'] ?? 0)),
        ];
    }

    public function movementTypeLabel(): ?string
    {
        $type = $this->data['type'] ?? null;

        return $type ? TypeInventoryManagementEnum::from($type)->getLabel() : null;
    }

    public function movementTypeColor(): string
    {
        $type = $this->data['type'] ?? null;

        return $type ? TypeInventoryManagementEnum::getColor($type) : 'gray';
    }

    public function register(): void
    {
        $data = $this->form->getState();

        if (!$this->validateLines()) {
            return;
        }

        $inventoryType = $data['inventory_type'];
        $movementType = $data['type'];
        $description = $data['description'];
        $service = app(ManagementInventoryService::class);

        try {
            // Todo o nada: si una línea no tiene existencia suficiente se
            // cae el lote completo, en vez de dejar registrados los
            // anteriores y que nadie sepa cuáles pasaron.
            DB::transaction(function () use ($inventoryType, $movementType, $description, $service) {
                foreach ($this->lines as $line) {
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

        $count = count($this->lines);

        Notification::make()
            ->title($count === 1 ? 'Movimiento registrado' : "{$count} movimientos registrados")
            ->body(TypeInventoryManagementEnum::from($movementType)->getLabel() . ' · ' . $description)
            ->success()
            ->send();

        // Se conservan sucursal, tipo y descripción para encadenar otro lote.
        $this->resetLines();
    }

    /** Valida las líneas, que viven fuera del formulario de Filament. */
    protected function validateLines(): bool
    {
        $this->resetErrorBag();

        if (empty($this->lines)) {
            Notification::make()
                ->title('Agregue al menos un producto')
                ->body('Busque el producto arriba y agréguelo al lote.')
                ->warning()
                ->send();

            return false;
        }

        $valid = true;

        foreach ($this->lines as $index => $line) {
            $quantity = $line['quantity'] ?? null;

            if ($quantity === null || $quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
                $this->addError("lines.{$index}.quantity", 'Ingrese una cantidad mayor a cero.');
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * @return Collection<int, array{id: int, label: string, stock: string}>
     */
    protected function availableInventories($branchId): Collection
    {
        $inventoryType = $this->data['inventory_type'] ?? self::TYPE_FINISHED;

        if ($inventoryType === self::TYPE_RAW) {
            return RawMaterialsInventory::where('branch_id', $branchId)
                ->orderBy('name')
                ->get()
                ->map(fn (RawMaterialsInventory $item) => [
                    'id' => $item->id,
                    'label' => $item->name,
                    'stock' => number_format((float) $item->stock, 2) . ' ' . $item->unit_type,
                ])
                ->values();
        }

        return FinishedProductInventory::with('product')
            ->where('branch_id', $branchId)
            ->get()
            ->sortBy(fn (FinishedProductInventory $item) => $item->product?->name ?? '')
            ->map(fn (FinishedProductInventory $item) => [
                'id' => $item->id,
                'label' => $item->product?->name ?? "Producto #{$item->product_id}",
                'stock' => number_format((float) $item->stock, 2) . ' unidades',
            ])
            ->values();
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
