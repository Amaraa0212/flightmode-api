<?php

namespace App\Enums;

enum PassengerType: string
{
    case Adult = 'ADT';
    case Child = 'CHD';
    case Infant = 'INF';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
