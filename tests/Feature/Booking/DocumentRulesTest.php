<?php

namespace Tests\Feature\Booking;

use App\Exceptions\BusinessRuleException;
use App\Models\Airport;
use App\Models\Flight;
use App\Services\Booking\PassengerRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Travel documents: passport validity, Mongolian national ID card (no end date from 45), infants with an adult. */
class DocumentRulesTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $international;

    private Flight $domestic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->international = $this->makeFlight(now()->addDays(10)->setTime(8, 0));
        $ulg = Airport::create(['iata_code' => 'ULG', 'name' => 'Ulgii', 'city' => 'Ulgii', 'country_id' => $this->ubn->country_id, 'timezone' => 'Asia/Hovd']);
        $this->domestic = $this->makeFlight(now()->addDays(10)->setTime(9, 0), ['destination_airport_id' => $ulg->id, 'flight_number' => 'TA200']);
    }

    private function person(array $overrides = []): array
    {
        return array_merge([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'nationality' => 'MN',
            'document_type' => 'passport',
            'document_expires_at' => now()->addYears(3)->toDateString(),
        ], $overrides);
    }

    private function rejects(string $code, Flight $flight, array $adult, ?array $infant = null): void
    {
        try {
            app(PassengerRules::class)->validate($flight->load(['origin', 'destination']), $adult, $infant);
            $this->fail("Expected {$code}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    private function accepts(Flight $flight, array $adult, ?array $infant = null): void
    {
        app(PassengerRules::class)->validate($flight->load(['origin', 'destination']), $adult, $infant);
        $this->addToAssertionCount(1);
    }

    public function test_passport_must_be_valid_six_months_after_an_international_departure(): void
    {
        $this->rejects('passport_validity', $this->international, $this->person(['document_expires_at' => now()->addDays(10)->addMonths(5)->toDateString()]));
        $this->accepts($this->international, $this->person(['document_expires_at' => now()->addDays(10)->addMonths(7)->toDateString()]));
        // domestic: valid on the day is enough
        $this->accepts($this->domestic, $this->person(['document_expires_at' => now()->addDays(20)->toDateString()]));
        $this->rejects('document_expired', $this->domestic, $this->person(['document_expires_at' => now()->addDays(5)->toDateString()]));
    }

    public function test_mongolian_national_id_has_no_end_date_from_45(): void
    {
        $id = ['document_type' => 'national_id', 'document_expires_at' => null];
        // 50 years old: no expiry (0000/00/00) on a domestic flight
        $this->accepts($this->domestic, $this->person(array_merge($id, ['date_of_birth' => now()->subYears(50)->toDateString()])));
        // 30 years old: the card's end date is checked
        $this->rejects('document_expired', $this->domestic, $this->person(['document_type' => 'national_id', 'document_expires_at' => now()->subDay()->toDateString()]));
        // international flights need a passport; foreigners have no Mongolian ID card
        $this->rejects('national_id_international', $this->international, $this->person(array_merge($id, ['date_of_birth' => now()->subYears(50)->toDateString()])));
        $this->rejects('national_id_nationality', $this->domestic, $this->person(array_merge($id, ['nationality' => 'KR', 'date_of_birth' => now()->subYears(50)->toDateString()])));
    }

    public function test_infant_travels_with_an_adult_and_its_passport_is_checked(): void
    {
        $infant = $this->person(['date_of_birth' => now()->subMonths(8)->toDateString()]);
        $this->accepts($this->international, $this->person(), $infant);
        $this->rejects('infant_needs_adult', $this->international, $this->person(['date_of_birth' => now()->subYears(10)->toDateString()]), $infant);
        $this->rejects('passport_validity', $this->international, $this->person(), array_merge($infant, ['document_expires_at' => now()->addMonths(2)->toDateString()]));
    }
}
