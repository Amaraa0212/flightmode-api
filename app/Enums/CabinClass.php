<?php

namespace App\Enums;

enum CabinClass: string
{
    case First = 'F';
    case Business = 'C';
    case PremiumEconomy = 'W';
    case Economy = 'Y';

    public function label(): string
    {
        return match ($this) {
            self::First => 'F-First',
            self::Business => 'C-Business',
            self::PremiumEconomy => 'W-Premium Economy',
            self::Economy => 'Y-Economy',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
