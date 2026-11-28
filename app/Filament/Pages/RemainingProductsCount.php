<?php

namespace App\Filament\Pages;

use App\Constants\PermissionConstants;
use App\Exceptions\RemainingProductCountException;
use App\Models\Employee;
use App\Services\RemainingProductsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;

/**
 * Conteo de productos sobrantes para el cajero.
 *
 * El cajero registra lo que cada vendedor trae de regreso; el cuadre diario
 * usa ese conteo para cobrar el faltante. Esta página no muestra montos ni
 * listas de precios: toda la regla vive en RemainingProductsService.
 */
class RemainingProductsCount extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Conteo de sobrantes';

    protected static ?string $title = 'Conteo de sobrantes';

    protected static ?string $slug = 'remaining-products-count';

    protected static string $view = 'filament.pages.remaining-products-count';

    #[Url(as: 'employee')]
    public ?string $employee_id = null;

    #[Url(as: 'date')]
    public ?string $date = null;

    /** @var array<int, array{id: int, name: string}> */
    public array $employees = [];

    /** Filas del servicio (unidades, sin montos). */
    public array $rows = [];

    /** Cantidad escrita por fila, indexada por id del detalle. */
    public array $counts = [];

    public bool $closed = false;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        // El conteo modifica returned_quantity del detalle de la asignación:
        // el cajero ya tiene ese permiso (decisión 8), sin volver a sembrar roles.
        return $user !== null
            && $user->hasAnyPermission([PermissionConstants::DETAIL_ASSIGNED_PRODUCT_UPDATE]);
    }

    public function mount(): void
    {
        $this->date = $this->normalizeDate($this->date) ?? now()->toDateString();
        $this->employees = Employee::orderBy('first_name')
            ->get()
            ->map(fn (Employee $employee) => ['id' => $employee->id, 'name' => $employee->full_name])
            ->all();

        $this->loadRows();
    }

    public function updatedEmployeeId(): void
    {
        $this->loadRows();
    }

    public function updatedDate(): void
    {
        $this->date = $this->normalizeDate($this->date) ?? now()->toDateString();
        $this->loadRows();
    }

    public function save(int $detailId): void
    {
        $this->authorizeWrite();

        $value = $this->counts[$detailId] ?? null;

        if (! is_numeric($value)) {
            $this->notifyError('Ingrese una cantidad válida.');

            return;
        }

        $this->runSafely(function () use ($detailId, $value) {
            $return = $this->service()->registerCount(
                (int) $this->employee_id,
                $this->date,
                $detailId,
                (float) $value,
                Auth::user()
            );

            $row = collect($this->rows)->firstWhere('id', $detailId);

            Notification::make()
                ->title($return
                    ? 'Conteo guardado: '.($row['product_name'] ?? 'producto').' → '.(float) $value
                    : 'Sin cambios')
                ->success()
                ->send();
        });
    }

    public function returnAllAction(): Action
    {
        return Action::make('returnAll')
            ->label('Devolver todo lo sobrante')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('¿Devolver todo lo sobrante?')
            ->modalDescription('Se registrarán como devueltos los productos con sobrante que aún no tienen conteo. Los productos ya contados no cambian.')
            ->modalSubmitActionLabel('Sí, devolver todo')
            ->visible(fn () => filled($this->employee_id) && ! $this->closed && ! empty($this->rows))
            ->action(fn () => $this->returnAll());
    }

    public function returnAll(): void
    {
        $this->authorizeWrite();

        $this->runSafely(function () {
            $processed = $this->service()->returnAllRemaining((int) $this->employee_id, $this->date, Auth::user());

            Notification::make()
                ->title($processed > 0
                    ? "Se registraron {$processed} productos como devueltos."
                    : 'No hay productos sobrantes sin conteo.')
                ->success()
                ->send();
        });
    }

    private function loadRows(): void
    {
        if (! filled($this->employee_id) || ! $this->date) {
            $this->rows = [];
            $this->counts = [];
            $this->closed = false;

            return;
        }

        $employeeId = (int) $this->employee_id;

        $this->rows = $this->service()->listForEmployee($employeeId, $this->date);
        $this->counts = collect($this->rows)
            ->mapWithKeys(fn (array $row) => [$row['id'] => $row['returned_quantity']])
            ->all();
        $this->closed = $this->service()->isClosed($employeeId, $this->date);
    }

    /** Ejecuta una escritura con manejo de errores seguro y recarga el estado. */
    private function runSafely(callable $write): void
    {
        try {
            $write();
        } catch (RemainingProductCountException $e) {
            $this->notifyError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Conteo de sobrantes: error inesperado', [
                'employee_id' => $this->employee_id,
                'date' => $this->date,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            $this->notifyError('No se pudo guardar el conteo. Intente de nuevo.');
        }

        $this->loadRows();
    }

    private function authorizeWrite(): void
    {
        // Filament solo evalúa canAccess() al montar; las acciones Livewire
        // posteriores lo vuelven a verificar (W3).
        abort_unless(static::canAccess(), 403);
    }

    private function notifyError(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }

    private function normalizeDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function service(): RemainingProductsService
    {
        return app(RemainingProductsService::class);
    }
}
