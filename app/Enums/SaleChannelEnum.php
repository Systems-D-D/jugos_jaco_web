<?php

namespace App\Enums;

/**
 * Canal de origen de la venta. Es informativo (reportería, UI): la lógica
 * de reversión de una anulación NUNCA debe decidir por este campo, sino por
 * evidencia directa (existe AssignedProduct / existen asientos de
 * management_inventory para la venta). Ver
 * docs/devflow/specs/2026-08-10-sale-deletion-analysis.md §6.
 */
enum SaleChannelEnum: string
{
    case APP = 'app';
    case WEB = 'web';

    public function getLabel(): string
    {
        return match ($this) {
            self::APP => 'Aplicación móvil',
            self::WEB => 'Sitio web',
        };
    }

    public static function getOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
            ->toArray();
    }
}
