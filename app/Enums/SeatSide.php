<?php

namespace App\Enums;

enum SeatSide: string
{
    case Left = 'left';
    case Middle = 'middle';
    case Right = 'right';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
