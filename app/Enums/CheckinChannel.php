<?php

namespace App\Enums;

enum CheckinChannel: string
{
    case Dcs = 'dcs';
    case Online = 'online';
    case Agency = 'agency';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
