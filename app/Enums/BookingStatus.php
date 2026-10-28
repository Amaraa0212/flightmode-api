<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    /** Seats kept without tickets until ticketing_deadline_at (the fare class ticket time limit) */
    case OnHold = 'on_hold';
    case Waitlisted = 'waitlisted';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
