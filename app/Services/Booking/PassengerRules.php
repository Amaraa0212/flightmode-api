<?php

namespace App\Services\Booking;

use App\Enums\PassengerType;
use App\Exceptions\BusinessRuleException;
use App\Models\Flight;
use App\Support\PassengerAge;
use Illuminate\Support\Carbon;

/** Date / document rules a passenger must satisfy for a flight. */
class PassengerRules
{
    public const NATIONAL_ID = 'national_id';

    /**
     * @param  array<string, mixed>  $adult
     * @param  array<string, mixed>|null  $infant
     */
    public function validate(Flight $flight, array $adult, ?array $infant = null): void
    {
        $departure = $flight->departureAt();
        $international = ! $flight->isDomestic();

        $this->assertBirthDate($adult['date_of_birth'] ?? null, $departure, 'Passenger');

        if ($infant !== null) {
            $adultType = PassengerAge::typeOn($this->date($adult['date_of_birth'] ?? null), $departure);
            if ($adultType !== PassengerType::Adult) {
                throw BusinessRuleException::make('infant_needs_adult', 'A passenger travelling with an infant must be an adult (12+).');
            }

            $this->assertBirthDate($infant['date_of_birth'] ?? null, $departure, 'Infant', required: true);
            if (PassengerAge::typeOn($this->date($infant['date_of_birth']), $departure) !== PassengerType::Infant) {
                throw BusinessRuleException::make('infant_too_old', 'An infant must be under 2 years old on the departure date.');
            }
        }

        $this->assertDocument($adult, $departure, $international, 'Passenger');
        if ($infant !== null) {
            $this->assertDocument($infant, $departure, $international, 'Infant');
        }
    }

    /**
     * Travel document of one person:
     *  - Mongolian national ID card: MN citizens only, domestic flights only; from age 45 it has no end date
     *    (expiry 0000/00/00), so its expiry is not checked
     *  - international: the passport must be valid PASSPORT_MIN_VALIDITY months after departure
     *  - domestic: the document must be valid on the departure date
     *
     * @param  array<string, mixed>  $person
     */
    private function assertDocument(array $person, Carbon $departure, bool $international, string $who): void
    {
        if (($person['document_type'] ?? null) === self::NATIONAL_ID) {
            if (strtoupper((string) ($person['nationality'] ?? '')) !== 'MN') {
                throw BusinessRuleException::make('national_id_nationality', "{$who}: a national ID card is accepted for Mongolian citizens (MN) only.");
            }
            if ($international) {
                throw BusinessRuleException::make('national_id_international', "{$who}: international flights need a passport, not a national ID card.");
            }
            $age = PassengerAge::years($this->date($person['date_of_birth'] ?? null), $departure);
            if ($age !== null && $age >= (int) config('flightmode.booking.national_id_no_expiry_age', 45)) {
                return; // valid for life
            }
        }

        if ($international) {
            $this->assertPassportValidity($person['document_expires_at'] ?? null, $departure, $who);

            return;
        }

        $expires = $this->date($person['document_expires_at'] ?? null);
        if ($expires && $expires->lt($departure->copy()->startOfDay())) {
            throw BusinessRuleException::make('document_expired', "{$who} document has expired before the departure date.");
        }
    }

    private function assertBirthDate(mixed $value, Carbon $departure, string $who, bool $required = false): void
    {
        $dob = $this->date($value);
        if (! $dob) {
            if ($required) {
                throw BusinessRuleException::make('birth_date_required', "{$who} date of birth is required.");
            }

            return;
        }

        if ($dob->isFuture() || $dob->gte($departure)) {
            throw BusinessRuleException::make('birth_date_invalid', "{$who} date of birth must be before the departure date.");
        }
    }

    private function assertPassportValidity(mixed $value, Carbon $departure, string $who): void
    {
        $expires = $this->date($value);
        if (! $expires) {
            return;
        }

        $months = (int) config('flightmode.booking.passport_min_validity_months', 6);
        if ($expires->lt($departure->copy()->startOfDay()->addMonths($months))) {
            throw BusinessRuleException::make('passport_validity',
                "{$who} passport must be valid at least {$months} months after the departure date.");
        }
    }

    private function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
