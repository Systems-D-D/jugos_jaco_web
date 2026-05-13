# Plan — Bulk Price Update

**Goal:** Add a header action to the ProductPriceResource list page that opens a modal form to mass-update prices filtered by TypePrice, Category, and ProductUnit.
**Architecture:** `docs/devflow/specs/2026-05-11-bulk-price-update-design.md`
**Mockup:** `docs/devflow/mockups/2026-05-11-bulk-price-update-mockup.html`
**Tech Stack:** Laravel 11, Filament v3, PHP 8.2+

---

## File Map

**Modify:**
- `app/Filament/Resources/ProductPriceResource/Pages/ListProductPrices.php` — add `getHeaderActions()` with bulk price update action (modal form + handler)

---

### Task 1: Implement Bulk Price Update Header Action

> **Risk:** 🟢 LOW — single file change, read-only queries followed by atomic SQL UPDATE
> **Affects existing:** None (new action, no existing behavior changed)
> **Reference implementation:** `app/Filament/Actions/TransferClientsAction.php` — modal header action pattern with form schema, reactive fields, and Notification-based feedback

**Files:**
- Modify: `app/Filament/Resources/ProductPriceResource/Pages/ListProductPrices.php`

- [ ] **Step 1: Add imports at the top of `ListProductPrices.php`**

Add the following `use` statements after the existing imports:

```php
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 2: Implement `getHeaderActions()` with the bulk update action**

Replace the existing `getHeaderActions()` method with:

```php
protected function getHeaderActions(): array
{
    return [
        Actions\CreateAction::make(),
        Action::make('bulkPriceUpdate')
            ->label('Actualizar Precios Masivamente')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->modalHeading('Actualización Masiva de Precios')
            ->modalDescription('Actualiza todos los precios que coincidan con los filtros seleccionados. Solo se modificarán productos activos.')
            ->form(function (Form $form) {
                return $form->schema([
                    Select::make('type_price_id')
                        ->label('Tipo de Precio (Escala)')
                        ->relationship('typePrice', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->placeholder('Seleccione un tipo de precio'),

                    Select::make('category_id')
                        ->label('Categoría de Producto')
                        ->relationship('product.category', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->placeholder('Seleccione una categoría')
                        ->reactive()
                        ->afterStateUpdated(fn(callable $set) => $set('product_unit_id', null)),

                    Select::make('product_unit_id')
                        ->label('Unidad de Medida')
                        ->options(function (callable $get) {
                            $categoryId = $get('category_id');
                            if (!$categoryId) {
                                return [];
                            }

                            return ProductUnit::whereHas('product', function ($query) use ($categoryId) {
                                $query->where('category_id', $categoryId)
                                    ->where('is_active', true);
                            })
                                ->active()
                                ->sellable()
                                ->with('unit')
                                ->get()
                                ->mapWithKeys(function ($productUnit) {
                                    return [
                                        $productUnit->id => $productUnit->unit->name
                                            . ' (' . $productUnit->conversion_factor . ')',
                                    ];
                                });
                        })
                        ->required()
                        ->searchable()
                        ->placeholder('Seleccione una unidad')
                        ->helperText('Solo se muestran unidades disponibles en la categoría seleccionada.'),

                    TextInput::make('price')
                        ->label('Nuevo Precio')
                        ->required()
                        ->numeric()
                        ->prefix('L.')
                        ->minValue(0.01)
                        ->maxValue(99999.99)
                        ->step(0.01)
                        ->helperText('Se actualizarán todos los precios que coincidan con los filtros.'),
                ]);
            })
            ->action(function (array $data) {
                $updated = ProductPrice::where('type_price_id', $data['type_price_id'])
                    ->where('product_unit_id', $data['product_unit_id'])
                    ->whereHas('product', function ($query) use ($data) {
                        $query->where('category_id', $data['category_id'])
                            ->where('is_active', true);
                    })
                    ->update(['price' => $data['price']]);

                if ($updated > 0) {
                    Notification::make()
                        ->title('¡Actualización completada!')
                        ->body("{$updated} precio(s) actualizado(s) exitosamente.")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Sin coincidencias')
                        ->body('No se encontraron precios con los filtros seleccionados. Verifica Tipo de Precio, Categoría y Unidad.')
                        ->warning()
                        ->send();
                }
            })
            ->modalFooterActionsAlignment('end')
            ->modalSubmitActionLabel('Actualizar Precios'),
    ];
}
```

- [ ] **Step 3: Run lint**

```bash
./vendor/bin/pint app/Filament/Resources/ProductPriceResource/Pages/ListProductPrices.php
```

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/ProductPriceResource/Pages/ListProductPrices.php
git commit -m "feat(product-prices): add bulk price update header action with modal form

Add 'Actualizar Precios Masivamente' header action to the
ProductPrice list page. Opens a modal to filter by TypePrice,
Category, and ProductUnit, then applies the new price to all
matching active product prices via a single SQL UPDATE."
```

---

### Self-Review Checklist
- [x] All spec requirements are covered
- [x] Each task has a commit checkpoint
- [x] Code snippets are complete (not partial)
- [x] Tests excluded per user request ("No configurar tests")
- [x] Dependencies between tasks are respected
- [x] No orphan files
- [x] HTML wireframe mockup generated at `docs/devflow/mockups/2026-05-11-bulk-price-update-mockup.html`
