# Architecture Spec — Bulk Price Update

**Feature Slug:** `bulk-price-update`
**Date:** 2026-05-11

---

## Context

Admins currently must edit each `ProductPrice` record individually to change prices. When a price list changes (e.g., "all retail prices for juices in 1L bottles are now L.35"), they must manually find and edit dozens of records. This feature provides a bulk update modal that filters by TypePrice (escala), Category, and ProductUnit, applying a new price to all matching records in a single operation.

---

## Architecture

### High-Level Design

A **Filament header action** is added to the `ListProductPrices` page. The action opens a modal with a form containing three select filters (TypePrice, Category, ProductUnit) and a numeric price input. On submit, all `ProductPrice` records matching the filter criteria are batch-updated.

### Data Flow

```
User → ListProductPrices page (Filament)
       │
       ├── Header action "Actualizar Precios Masivamente" button
       │     │
       │     └── Modal Form
       │           ├── Select: type_price_id   (TypePrice)
       │           ├── Select: category_id     (Category)   [reactive → resets product_unit_id]
       │           ├── Select: product_unit_id  (ProductUnit) [options filtered by selected category]
       │           └── TextInput: price         (numeric, L. format)
       │
       └── On Submit
             ├── Validate input
             ├── Query: ProductPrice
             │     ::where('type_price_id', $data['type_price_id'])
             │     ::whereHas('product', fn($q) => $q->where('category_id', $data['category_id'])->where('is_active', true))
             │     ::where('product_unit_id', $data['product_unit_id'])
             ├── Update all matching records: price = $data['price']
             ├── Count updated records
             └── Send notification: "X precios actualizados exitosamente"
```

### Component Map

| Component | File | Status |
|-----------|------|--------|
| `ListProductPrices` | `app/Filament/Resources/ProductPriceResource/Pages/ListProductPrices.php` | **Modified** — add `getHeaderActions()` with bulk update action |
| `ProductPrice` | `app/Models/ProductPrice.php` | Unchanged (uses existing relationships) |
| `ProductUnit` | `app/Models/ProductUnit.php` | Unchanged (uses existing `scopeSellable`, `scopeActive`) |

---

## Data Structures

### Existing (relevant)

**ProductPrice** (`products_prices`):
```
id, type_price_id (FK→types_prices), product_id (FK→products), product_unit_id (FK→product_units),
price (decimal 8,2), tax_category_id (FK, nullable), price_include_tax (boolean), timestamps
```

**ProductUnit** (`product_units`):
```
id, product_id (FK→products), unit_id (FK→units), conversion_factor (decimal 10,2),
is_base_unit, is_sellable, is_purchasable, is_active (boolean), timestamps
```

### Form Schema (Modal)

```php
[
    Select::make('type_price_id')
        ->label('Tipo de Precio (Escala)')
        ->relationship('typePrice', 'name')
        ->required()
        ->searchable()
        ->preload(),

    Select::make('category_id')
        ->label('Categoría')
        ->relationship('product.category', 'name')  // via ProductPrice→product→category
        ->required()
        ->searchable()
        ->preload()
        ->reactive()
        ->afterStateUpdated(fn(callable $set) => $set('product_unit_id', null)),

    Select::make('product_unit_id')
        ->label('Unidad de Medida')
        ->options(function (callable $get) {
            $categoryId = $get('category_id');
            if (!$categoryId) return [];
            return ProductUnit::whereHas('product', fn($q) => $q
                    ->where('category_id', $categoryId)->where('is_active', true)
                )
                ->active()
                ->sellable()
                ->with('unit')
                ->get()
                ->mapWithKeys(fn($pu) => [$pu->id => $pu->unit->name . ' (' . $pu->conversion_factor . ')']);
        })
        ->required()
        ->searchable(),

    TextInput::make('price')
        ->label('Nuevo Precio')
        ->required()
        ->numeric()
        ->prefix('L.')
        ->minValue(0.01)
        ->maxValue(99999.99)
        ->step(0.01),
]
```

### Update Query (Action Handler)

```php
$updated = ProductPrice::where('type_price_id', $data['type_price_id'])
    ->where('product_unit_id', $data['product_unit_id'])
    ->whereHas('product', fn($q) => $q
        ->where('category_id', $data['category_id'])
        ->where('is_active', true)
    )
    ->update(['price' => $data['price']]);
```

---

## Reusability Decisions

| Existing component | Current purpose | Reusable for | Decision | Justification |
|--------------------|-----------------|--------------|----------|---------------|
| `ProductPrice::scopeTypePrice()` | Filter by type_price_id | Pre-filtering query | **Reused** | Already scopes by type_price_id |
| `ProductUnit::scopeActive()` | Filter active units | ProductUnit dropdown options | **Reused** | Only show active, sellable units |
| `ProductUnit::scopeSellable()` | Filter sellable units | ProductUnit dropdown options | **Reused** | Only units marked as sellable |
| `Product::scopeIsActive()` | Filter active products | Update query filter | **Reused** | Only update active products |
| `ProductPriceResource::form()` | Individual price form | Price input field config | **Pattern reused** | Same prefix `L.`, same min/max/step |
| `TransferClientsAction` | Custom modal action pattern | Modal form structure | **Pattern reference** | Same approach: header action → modal → form → batch operation |
| `Notification::make()` | Filament notifications | Success/error feedback | **Reused** | Consistent UX pattern |

---

## UI Mockups

### Default State — List Page Header

```
┌─────────────────────────────────────────────────────────────────┐
│  Precios de productos                                            │
│                                                                  │
│  [+ Crear]  [🔄 Actualizar Precios Masivamente]                   │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │ Filters: [Producto ▼] [Unidad ▼] [Impuesto ▼] ...           ││
│  ├──────────────────────────────────────────────────────────────┤│
│  │ ☐ │ Producto          │ Unidad       │ Tipo Precio │ Precio  ││
│  │ ☐ │ Jugo de Naranja   │ Litro (1.00) │ Minorista   │ L.25.00 ││
│  │ ☐ │ Jugo de Manzana   │ Litro (1.00) │ Mayorista   │ L.20.00 ││
│  └──────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

### Modal — Form State

```
┌──────────────────────────────────────────────────┐
│  Actualización Masiva de Precios                 │
│  ─────────────────────────────────────────────── │
│  Actualiza todos los precios que coincidan con   │
│  los filtros seleccionados.                      │
│                                                  │
│  Tipo de Precio (Escala)                         │
│  ┌──────────────────────────────────────────────┐│
│  │ Minorista                              [▼]   ││
│  └──────────────────────────────────────────────┘│
│                                                  │
│  Categoría                                       │
│  ┌──────────────────────────────────────────────┐│
│  │ Jugos                                 [▼]   ││
│  └──────────────────────────────────────────────┘│
│                                                  │
│  Unidad de Medida                                │
│  ┌──────────────────────────────────────────────┐│
│  │ Litro (1.00)                          [▼]   ││
│  └──────────────────────────────────────────────┘│
│                                                  │
│  Nuevo Precio                                    │
│  ┌──────────────────────────────────────────────┐│
│  │ L. | 35.00______________________________     ││
│  └──────────────────────────────────────────────┘│
│                                                  │
│              [Cancelar]  [Actualizar Precios]    │
└──────────────────────────────────────────────────┘
```

### Empty / Warning State

When no records match the selected filters after submission:

```
┌───────────────────────────────────────┐
│  ⚠️ Sin coincidencias                │
│  ──────────────────────────────────── │
│  No se encontraron precios que        │
│  coincidan con los filtros            │
│  seleccionados. Verifica la           │
│  combinación de Tipo de Precio,       │
│  Categoría y Unidad de Medida.        │
│                                       │
│                   [Cerrar]            │
└───────────────────────────────────────┘
```

---

## Risk Assessment

| Risk | Level | Mitigation |
|------|-------|------------|
| Accidental mass price overwrite | 🟡 MEDIUM | Modal description warns user; show count of affected records before confirmation (optional enhancement) |
| Performance with large datasets | 🟢 LOW | Mass update via single SQL `UPDATE ... WHERE` query; no row-by-row processing |
| Selecting wrong filters | 🟡 MEDIUM | Reactive dropdown limits ProductUnit options to those available in selected category |

---

## Design Decisions

| Decision | Alternatives | Reasoning |
|----------|-------------|-----------|
| Header action (not bulk action) | BulkAction requires row selection, which conflicts with the filter-based approach | Header action is the Filament-idiomatic way for "form-based actions on the whole dataset" |
| Inline action on `ListProductPrices` page (not separate Action class) | Dedicated `App\Filament\Actions\BulkPriceUpdateAction` class | Simpler for a form with 1-step action; no need for reuse or complex setup |
| ProductUnit filtered by Category | Unfiltered (all product units) | Reduces user error; only shows units actually linked to products in the selected category |
| Only update `price` field (not tax fields) | Update all price fields | Per user's request scope; tax configuration is a separate concern |
| Exclude inactive products (`is_active = false`) | Include all products | Matches business rule: inactive products shouldn't have their prices maintained |

---

## Constraints

- Only affects existing ProductPrice records (no creation of new records)
- Only updates the `price` column; `tax_category_id` and `price_include_tax` are untouched
- Product must be active (`products.is_active = true`)
- ProductUnit must be active and sellable
- Admin-only access (inherent in Filament panel, no extra permission gate needed)
