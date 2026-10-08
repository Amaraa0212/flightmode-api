<?php

namespace App\Enums;

enum RoleScope: string
{
    case Admin = 'admin';
    case Airline = 'airline';
    case Agency = 'agency';
    case Airport = 'airport';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
