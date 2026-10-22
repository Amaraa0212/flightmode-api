<?php

namespace App\Enums;

/** Scheduled (хуваарьт) flights sell fares; charter (захиалгат) flights take every bag free, with no rule or limit (legacy DCS). */
enum FlightServiceType: string
{
    case Scheduled = 'scheduled';
    case Charter = 'charter';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
