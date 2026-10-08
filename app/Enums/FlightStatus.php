<?php

namespace App\Enums;

enum FlightStatus: string
{
    case Scheduled = 'scheduled';
    case CheckinOpen = 'checkin_open';
    case CheckinClosed = 'checkin_closed';
    case GateClosed = 'gate_closed';
    case Departed = 'departed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
