<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        // DeleteAction retirado: una venta nunca se borra físicamente, se anula
        // (ver docs/devflow/specs/2026-08-10-sale-deletion-analysis.md).
        return [
            Actions\ViewAction::make(),
        ];
    }
}
