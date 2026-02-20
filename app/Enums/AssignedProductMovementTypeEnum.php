<?php

namespace App\Enums;

enum AssignedProductMovementTypeEnum: string
{
    case CHANGE = 'change';
    case ROYALTY = 'royalty';

    public function getLabel(): string
    {
        return match($this) {
            self::CHANGE => 'Cambio',
            self::ROYALTY => 'Regalía',
        };
    }

    public function getColor(): string
    {
        return match($this) {
            self::CHANGE => 'info',
            self::ROYALTY => 'success',
        };
    }

    public static function getOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->getLabel()])
            ->toArray();
    }
}
