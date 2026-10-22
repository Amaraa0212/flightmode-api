<?php

namespace App\Enums;

enum BaggageType: string
{
    case Passenger = 'passenger';
    case Crew = 'crew';
    /** freight / mail tagged at the counter — kept out of the passengers' bags */
    case Cargo = 'cargo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
