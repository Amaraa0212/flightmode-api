<?php

namespace App\Enums;

enum TripType: string
{
    case OneWay = 'OW';
    case RoundTrip = 'RT';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
