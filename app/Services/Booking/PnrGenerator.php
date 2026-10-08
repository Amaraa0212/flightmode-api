<?php

namespace App\Services\Booking;

use App\Models\Booking;

class PnrGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        $length = (int) config('flightmode.booking.pnr_length', 6);

        do {
            $pnr = '';
            for ($i = 0; $i < $length; $i++) {
                $pnr .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Booking::withTrashed()->where('pnr', $pnr)->exists());

        return $pnr;
    }
}
