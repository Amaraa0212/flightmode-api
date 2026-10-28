<?php

namespace App\Enums;

enum ActivityChannel: string
{
    case Admin = 'admin';
    case Dcs = 'dcs';
    case Gate = 'gate';
    case Online = 'online';
    case Agency = 'agency';
    case AgencyApi = 'agency_api';
    case Auth = 'auth';
    /** Scheduled jobs (e.g. holds cancelled at their ticket time limit) */
    case System = 'system';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
