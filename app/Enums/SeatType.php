<?php

namespace App\Enums;

enum SeatType: string
{
    case Window = 'window';
    case Middle = 'middle';
    case Aisle = 'aisle';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
