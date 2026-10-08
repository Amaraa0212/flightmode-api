<?php

namespace App\Enums;

enum BookingSource: string
{
    case Admin = 'admin';
    case Counter = 'counter';
    case Agency = 'agency';
    case AgencyApi = 'agency_api';
    case Import = 'import';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
