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

        if ($international) {
            $this->assertPassportValidity($adult['document_expires_at'] ?? null, $departure, 'Passenger');
            if ($infant !== null) {
                $this->assertPassportValidity($infant['document_expires_at'] ?? null, $departure, 'Infant');
            }
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
