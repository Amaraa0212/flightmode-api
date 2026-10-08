<?php

namespace App\Support;

use App\Enums\PassengerType;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Single source of truth for IATA age bands: INF < 2, CHD < 12, ADT >= 12 (age on the travel date). */
final class PassengerAge
{
    public const INFANT_MAX_YEARS = 2;

    public const CHILD_MAX_YEARS = 12;

    public static function years(DateTimeInterface|string|null $dateOfBirth, DateTimeInterface $on): ?int
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return null;
        }
        $dob = $dateOfBirth instanceof DateTimeInterface ? Carbon::instance($dateOfBirth) : Carbon::parse($dateOfBirth);

        return (int) $dob->diffInYears(Carbon::instance($on), true);
    }

    public static function typeOn(DateTimeInterface|string|null $dateOfBirth, DateTimeInterface $on): PassengerType
    {
        $years = self::years($dateOfBirth, $on);

        return match (true) {
            $years === null => PassengerType::Adult,
            $years < self::INFANT_MAX_YEARS => PassengerType::Infant,
            $years < self::CHILD_MAX_YEARS => PassengerType::Child,
            default => PassengerType::Adult,
        };
    }
}
