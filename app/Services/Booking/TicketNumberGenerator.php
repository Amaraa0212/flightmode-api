<?php

namespace App\Services\Booking;

use App\Models\Airline;
use App\Models\Passenger;

/** 13-digit ticket number: airline numeric code, zero padded, passenger id (legacy format). */
class TicketNumberGenerator
{
    public function for(Airline $airline, Passenger $passenger): ?string
    {
        if (! $airline->numeric_code) {
            return null;
        }

        return $airline->numeric_code.str_pad((string) $passenger->id, 13 - strlen($airline->numeric_code), '0', STR_PAD_LEFT);
    }
}
