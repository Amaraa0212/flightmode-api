<?php

namespace App\Enums;

enum BaggageType: string
{
    case Passenger = 'passenger';
    case Crew = 'crew';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
