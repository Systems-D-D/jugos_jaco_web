<?php

namespace App\Filament\Resources;

use App\Enums\PaymentTypeEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use App\Enums\UserRole;
use App\Exceptions\SaleCancellationException;
use App\Filament\Resources\SaleResource\Pages;
use App\Models\Employee;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleCancellationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?string $navigationLabel = 'Ventas';

    protected static ?string $modelLabel = 'Venta';

    protected static ?string $pluralModelLabel = 'Ventas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('sale_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('client.full_name')
                    ->label('Cliente')
                    ->searchable(['clients.first_name', 'clients.last_name'])
                    ->placeholder('Cliente General'),
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(['employees.first_name', 'employees.last_name']),
                TextColumn::make('details_count')
                    ->counts('details')
                    ->label('Productos')
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('payment_term')
                    ->label('Término de Pago')
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Término')
                    ->color(fn($state) => $state?->getColor() ?? '')
                    ->badge(),
                TextColumn::make('payment_method')
                    ->label('Método de Pago')
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Método')
                    ->color(fn($state) => $state?->getColor() ?? '')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? 'Sin Estado')
                    ->color(fn($state) => $state?->getColor() ?? '')
                    ->badge()
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->options(Employee::get()->pluck('full_name', 'id')),
                Tables\Filters\SelectFilter::make('payment_term')
                    ->label('Término de Pago')
                    ->options(PaymentTermEnum::getOptions()),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Método de Pago')
                    ->options(PaymentTypeEnum::getOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(collect(SaleStatusEnum::cases())->mapWithKeys(fn($case) => [$case->value => $case->getLabel()])->toArray()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('desde')
                            ->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when(
                                $data['desde'],
                                fn ($query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('sale_date', '>=', $date),
                            )
                            ->when(
                                $data['hasta'],
                                fn ($query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('sale_date', '<=', $date),
                            );
                    })
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::makeTableCancelAction(),
            ]);
            // DeleteBulkAction retirado: una venta nunca se borra físicamente, se
            // anula (ver docs/devflow/specs/2026-08-10-sale-deletion-analysis.md).
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'view' => Pages\ViewSale::route('/{record}'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }

    /**
     * Acción "Anular" para la tabla de ventas. La lógica compartida con
     * ViewSale (visibilidad, formulario, manejo de errores) vive en los
     * métodos estáticos de abajo para no duplicarla entre ambos lugares:
     * Filament\Tables\Actions\Action y Filament\Actions\Action no comparten
     * jerarquía, así que cada página construye su propia instancia.
     */
    public static function makeTableCancelAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel')
            ->label('Anular')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Anular venta')
            ->modalSubmitActionLabel('Anular venta')
            ->form(fn () => static::cancelActionFormSchema())
            ->visible(fn (Sale $record) => static::saleCanBeCancelledByCurrentUser($record))
            ->action(fn (Sale $record, array $data) => static::handleCancelAction($record, $data));
    }

    /**
     * Autorización de la anulación (§9 del análisis):
     * - la venta ya anulada o facturada no se muestra (el resto de las
     *   precondiciones, R1/R2/R5, las valida el servicio y se comunican por
     *   notificación, porque dependen de estado que puede cambiar entre que
     *   se renderiza el botón y se hace clic).
     * - superadmin/admin: cualquier venta.
     * - cajero: sólo las que él mismo creó (`created_by`), no las
     *   atribuidas a su employee_id — ver nota en el análisis sobre la
     *   inconsistencia de `ListSales::getTableQuery` / `CashierSaleScope`.
     */
    public static function saleCanBeCancelledByCurrentUser(Sale $record): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (!$user || $record->isCancelled() || $record->isInvoiced()) {
            return false;
        }

        if (!$user->can('delete', $record)) {
            return false;
        }

        $isPrivileged = $user->hasAnyRole([UserRole::ADMIN->value, UserRole::SUPERADMIN->value]);

        if (!$isPrivileged && $user->hasRole(UserRole::CASHEER->value)) {
            return $record->created_by === $user->id;
        }

        return true;
    }

    public static function cancelActionFormSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('cash_amount_warning')
                ->label('Atención')
                ->content(fn (Sale $record) => sprintf(
                    'Esta venta a crédito tiene un abono inicial de L %s. Ese monto no genera un pago registrado; recuerde devolverlo al cliente.',
                    number_format((float) $record->cash_amount, 2)
                ))
                ->visible(fn (Sale $record) => $record->payment_term === PaymentTermEnum::CREDIT
                    && (float) $record->cash_amount > 0),
            Forms\Components\Textarea::make('reason')
                ->label('Motivo de la anulación')
                ->required()
                ->maxLength(500)
                ->rows(3),
        ];
    }

    public static function handleCancelAction(Sale $record, array $data): void
    {
        try {
            app(SaleCancellationService::class)->cancel($record, $data['reason']);

            Notification::make()
                ->title('Venta anulada correctamente')
                ->success()
                ->send();
        } catch (SaleCancellationException $e) {
            Notification::make()
                ->title('No se pudo anular la venta')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
