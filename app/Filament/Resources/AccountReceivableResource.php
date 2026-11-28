<?php

namespace App\Filament\Resources;

use App\Enums\AccountReceivableStatusEnum;
use App\Filament\Actions\AddPaymentAction;
use App\Filament\Resources\AccountReceivableResource\Pages;
use App\Filament\Resources\AccountReceivableResource\RelationManagers;
use App\Models\AccountReceivable;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Enums\PaymentTypeEnum;
use App\Enums\PaymentTermEnum;

class AccountReceivableResource extends Resource
{
    protected static ?string $model = AccountReceivable::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Cuentas por Cobrar';

    protected static ?string $modelLabel = 'Cuenta por Cobrar';

    protected static ?string $pluralModelLabel = 'Cuentas por Cobrar';

    protected static ?string $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('Información de la Cuenta')
                            ->schema([
                                Forms\Components\Select::make('sales_id')
                                    ->label('Venta (Opcional)')
                                    ->relationship('sale', 'invoice_number', function ($query) {
                                        $query->where('payment_term', PaymentTermEnum::CREDIT);
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->getOptionLabelFromRecordUsing(fn (Sale $record): string => static::saleOptionLabel($record))
                                    // Debe ir después de relationship(), que define su propia búsqueda solo por invoice_number.
                                    ->getSearchResultsUsing(fn (string $search): array => Sale::query()
                                        ->with('client')
                                        ->where('payment_term', PaymentTermEnum::CREDIT)
                                        ->searchByClient($search)
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(fn (Sale $sale): array => [$sale->id => static::saleOptionLabel($sale)])
                                        ->all())
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if ($state) {
                                            $sale = Sale::find($state);
                                            if ($sale) {
                                                $pendingAmount = $sale->total_amount - $sale->cash_amount;
                                                $set('total_amount', $pendingAmount);
                                                $set('remaining_balance', $pendingAmount);
                                                $set('name', $sale->client_display_name);
                                            }
                                        }
                                    })
                                    ->live(),

                                Forms\Components\TextInput::make('name')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(160),

                                Forms\Components\TextInput::make('total_amount')
                                    ->label('Monto Total')
                                    ->numeric()
                                    ->prefix('L.')
                                    ->step(0.01)
                                    ->required()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $set('remaining_balance', $state);
                                    })
                                    ->live(),

                                Forms\Components\TextInput::make('remaining_balance')
                                    ->label('Saldo Pendiente')
                                    ->numeric()
                                    ->prefix('L.')
                                    ->step(0.01)
                                    ->required()
                                    ->disabled()
                                    ->dehydrated(),

                                Forms\Components\Select::make('status')
                                    ->disabled()
                                    ->label('Estado')
                                    ->options(AccountReceivableStatusEnum::options())
                                    ->required()
                                    ->default(AccountReceivableStatusEnum::PENDING),

                                Forms\Components\DatePicker::make('due_date')
                                    ->label('Fecha de Vencimiento')
                                    ->displayFormat('d/m/Y'),
                            ])->columns(2),

                        Forms\Components\Section::make('Notas')
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->label('Notas')
                                    ->maxLength(120)
                                    ->rows(3),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('Información Adicional')
                            ->schema([
                                Forms\Components\Placeholder::make('created_at')
                                    ->label('Creado')
                                    ->content(fn (AccountReceivable $record): ?string => $record->created_at?->diffForHumans()),

                                Forms\Components\Placeholder::make('updated_at')
                                    ->label('Última Modificación')
                                    ->content(fn (AccountReceivable $record): ?string => $record->updated_at?->diffForHumans()),
                            ])
                            ->hidden(fn (?AccountReceivable $record) => $record === null),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sale.client.business_name')
                    ->label('Cliente')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhereHas('sale.client', fn (Builder $query) => $query
                            ->where('business_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"))))
                    ->sortable()
                    ->placeholder('Sin cliente')
                    ->getStateUsing(function (AccountReceivable $record): ?string {
                        $client = $record->sale?->client;

                        if (! $client) {
                            return $record->name;
                        }

                        return filled($client->business_name)
                            ? "{$client->business_name} ({$client->full_name})"
                            : $client->full_name;
                    }),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Monto Total')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_paid')
                    ->label('Pagado')
                    ->money('HNL')
                    ->getStateUsing(fn (AccountReceivable $record): float => $record->total_paid),

                Tables\Columns\TextColumn::make('remaining_balance')
                    ->label('Saldo Pendiente')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('progress_percentage')
                    ->label('Progreso')
                    ->getStateUsing(fn (AccountReceivable $record): string => number_format($record->progress_percentage, 1) . '%')
                    ->badge()
                    ->color(fn (AccountReceivable $record): string => match (true) {
                        $record->progress_percentage >= 100 => 'success',
                        $record->progress_percentage >= 50 => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (AccountReceivableStatusEnum $state): string => $state->getLabel())
                    ->color(fn (AccountReceivableStatusEnum $state): string => $state->getColor())
                    ->icon(fn (AccountReceivableStatusEnum $state): string => $state->getIcon()),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn (AccountReceivable $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(AccountReceivableStatusEnum::class),

                Tables\Filters\Filter::make('overdue')
                    ->label('Vencidas')
                    ->query(fn (Builder $query): Builder => $query->where('due_date', '<', now())->where('status', AccountReceivableStatusEnum::PENDING)),

                Tables\Filters\Filter::make('due_this_week')
                    ->label('Vencen esta semana')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('due_date', [now(), now()->addWeek()])),

                Tables\Filters\Filter::make('amount_range')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->label('Desde')
                            ->numeric()
                            ->prefix('L.'),
                        Forms\Components\TextInput::make('until')
                            ->label('Hasta')
                            ->numeric()
                            ->prefix('L.'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $query, $amount): Builder => $query->where('total_amount', '>=', $amount))
                            ->when($data['until'], fn (Builder $query, $amount): Builder => $query->where('total_amount', '<=', $amount));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                AddPaymentAction::table(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Etiqueta del selector de venta: "#ID - Negocio (Cliente) L.Saldo".
     */
    public static function saleOptionLabel(Sale $sale): string
    {
        return "#{$sale->id} - {$sale->client_display_name} L." . number_format($sale->total_amount - $sale->cash_amount, 2);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountReceivables::route('/'),
            'create' => Pages\CreateAccountReceivable::route('/create'),
            'view' => Pages\ViewAccountReceivable::route('/{record}'),
            'edit' => Pages\EditAccountReceivable::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', AccountReceivableStatusEnum::PENDING)->count();
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }
}
