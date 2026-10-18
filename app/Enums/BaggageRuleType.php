<?php

namespace App\Enums;

enum BaggageRuleType: string
{
    case Piece = 'piece';
    case Weight = 'weight';
    /** Piece cap (max_quantity) and a shared free kg pool (weight_kg × pieces): 2 bags of 22 kg total fit 1PC 23KG. */
    case Combined = 'combined';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
