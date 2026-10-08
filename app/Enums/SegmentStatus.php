<?php

namespace App\Enums;

enum SegmentStatus: string
{
    case Booked = 'booked';
    case Waitlisted = 'waitlisted';
    case CheckedIn = 'checked_in';
    case Boarded = 'boarded';
    case Flown = 'flown';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
