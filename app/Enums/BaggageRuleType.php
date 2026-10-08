<?php

namespace App\Enums;

enum BaggageRuleType: string
{
    case Piece = 'piece';
    case Weight = 'weight';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
