<?php

namespace Tests\Feature\Agency;

use App\Enums\SegmentStatus;
use App\Models\Agency;
use App\Models\Fare;
use App\Models\PassengerSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_agency_books_adult_with_infant_priced_from_fare_quota(): void
    {
        $flight = $this->makeFlight();
        $fare = Fare::create([
            'airline_id' => $this->airline->id, 'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'trip_type' => 'OW', 'cabin_class' => 'Y', 'fare_class' => 'M', 'valid_from' => today(), 'valid_to' => today()->addMonth(),
            'base_amount' => 1000000, 'tax_amount' => 50000, 'child_discount_percent' => 25, 'infant_discount_percent' => 90,
        ]);
        $allocation = $this->makeAllocation($flight, 5, ['Y' => 5], ['fares' => [['fare_id' => $fare->id, 'cabin_class' => 'Y', 'seats' => 2]]]);
        $bucketId = $allocation->fares->first()->id;
        $this->actingAsRole('agency-agent');

        $response = $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $allocation->id,
            'passengers' => [$this->passengerPayload([
                'first_name' => 'bold',
                'allocation_fare_id' => $bucketId,
                'infant' => ['first_name' => 'Baby', 'last_name' => 'Dorj', 'date_of_birth' => now()->subYear()->toDateString(),
                    'document_expires_at' => now()->addYears(4)->toDateString()],
            ])],
        ])->assertCreated();

        $response->assertJsonPath('data.passengers.0.first_name', 'BOLD')
            ->assertJsonPath('data.passengers.0.infant.type', 'INF');

        $adult = PassengerSegment::query()->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))->first();
        $infant = PassengerSegment::query()->whereHas('passenger', fn ($q) => $q->whereNotNull('infant_of_passenger_id'))->first();
        $this->assertSame('1000000.00', $adult->fare_amount);
        $this->assertSame('50000.00', $adult->tax_amount);
        $this->assertSame('100000.00', $infant->fare_amount);   // 10 % of base
        $this->assertSame('0.00', $infant->tax_amount);
        $this->assertSame('M', $adult->fare_class);
        $this->assertSame(13, strlen($adult->ticket_number));
        $this->assertStringStartsWith('123', $adult->ticket_number);

        $usage = $this->getJson("/api/v1/agency/allocations/{$allocation->id}")->assertOk()->json('data.usage');
        $this->assertSame(1, $usage['used']);          // infants do not use a seat
        $this->assertSame(1, $usage['fares'][0]['remaining']);
    }

    public function test_duplicate_document_on_same_flight_is_rejected(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight);
        $this->actingAsRole('agency-agent');
        $pax = $this->passengerPayload(['document_number' => 'E1234567']);

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$pax]])->assertCreated();
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$pax]])
            ->assertStatus(422)->assertJsonPath('code', 'duplicate_passenger');
    }

    public function test_cabin_quota_is_enforced(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight, 2, ['Y' => 1, 'C' => 1]);
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])
            ->assertStatus(422)->assertJsonPath('code', 'cabin_full');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload(['cabin_class' => 'C'])]])
            ->assertCreated();
    }

    public function test_waitlist_allocation_accepts_overbooking_as_waitlisted(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight, 1, ['Y' => 1], ['is_waitlist' => true]);
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])
            ->assertCreated()->assertJsonPath('data.status', 'waitlisted');

        $this->assertSame(1, PassengerSegment::query()->where('status', SegmentStatus::Waitlisted)->count());
    }

    public function test_passenger_rules(): void
    {
        $flight = $this->makeFlight();   // UBN → PQC is international
        $allocation = $this->makeAllocation($flight);
        $this->actingAsRole('agency-agent');
        $book = fn (array $pax) => $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$pax]]);

        $book($this->passengerPayload(['document_expires_at' => now()->addMonths(2)->toDateString()]))
            ->assertStatus(422)->assertJsonPath('code', 'passport_validity');

        $book($this->passengerPayload(['date_of_birth' => now()->subYears(10)->toDateString(), 'infant' => [
            'first_name' => 'A', 'last_name' => 'B', 'date_of_birth' => now()->subYear()->toDateString(),
        ]]))->assertStatus(422)->assertJsonPath('code', 'infant_needs_adult');

        $book($this->passengerPayload(['infant' => [
            'first_name' => 'A', 'last_name' => 'B', 'date_of_birth' => now()->subYears(3)->toDateString(),
            'document_expires_at' => now()->addYears(2)->toDateString(),
        ]]))->assertStatus(422)->assertJsonPath('code', 'infant_too_old');

        $book($this->passengerPayload(['date_of_birth' => now()->subMonths(6)->toDateString()]))
            ->assertStatus(422)->assertJsonPath('code', 'infant_without_adult');
    }

    public function test_names_deadline_blocks_agency(): void
    {
        $flight = $this->makeFlight(now()->addHour());
        $allocation = $this->makeAllocation($flight, 5, ['Y' => 5], ['deadline' => today()->toDateString()]);
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])
            ->assertStatus(422)->assertJsonPath('code', 'names_deadline_passed');
    }

    public function test_round_trip_allocation_creates_two_coupons_priced_once(): void
    {
        $out = $this->makeFlight(now()->addDays(3));
        $back = $this->makeFlight(now()->addDays(10), ['flight_number' => 'TA102', 'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id]);
        $allocation = $this->makeAllocation($out, 5, ['Y' => 5], ['return_flight_id' => $back->id, 'fares' => [
            ['cabin_class' => 'Y', 'seats' => 0, 'fare_amount' => 800000, 'tax_amount' => 40000],
        ]]);
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $allocation->id,
            'passengers' => [$this->passengerPayload(['allocation_fare_id' => $allocation->fares->first()->id])],
        ])->assertCreated()->assertJsonCount(2, 'data.passengers.0.segments');

        $segments = PassengerSegment::query()->orderBy('segment_no')->get();
        $this->assertSame([$out->id, $back->id], $segments->pluck('flight_id')->all());
        $this->assertSame('800000.00', $segments[0]->fare_amount);
        $this->assertSame('0.00', $segments[1]->fare_amount);
        $this->assertSame($segments[0]->ticket_number, $segments[1]->ticket_number);
    }

    public function test_round_trip_passenger_uses_one_seat_of_the_fare_quota(): void
    {
        $out = $this->makeFlight(now()->addDays(3));
        $back = $this->makeFlight(now()->addDays(10), ['flight_number' => 'TA102', 'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id]);
        $allocation = $this->makeAllocation($out, 5, ['Y' => 5], ['return_flight_id' => $back->id, 'fares' => [
            ['cabin_class' => 'Y', 'seats' => 2, 'fare_amount' => 800000, 'tax_amount' => 40000],
        ]]);
        $bucketId = $allocation->fares->first()->id;
        $this->actingAsRole('agency-agent');
        $book = fn () => $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $allocation->id,
            'passengers' => [$this->passengerPayload(['allocation_fare_id' => $bucketId])],
        ]);

        $book()->assertCreated();
        $usage = $this->getJson("/api/v1/agency/allocations/{$allocation->id}")->assertOk()->json('data.usage');
        $this->assertSame(1, $usage['fares'][0]['sold']);
        $this->assertSame(1, $usage['fares'][0]['remaining']);

        $book()->assertCreated();
        $book()->assertStatus(422)->assertJsonPath('code', 'fare_full');
    }

    public function test_manual_fare_applies_child_and_infant_discount(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight, 5, ['Y' => 5], ['fares' => [[
            'cabin_class' => 'Y', 'seats' => 0, 'fare_amount' => 800000, 'tax_amount' => 40000,
            'child_discount_percent' => 25, 'infant_discount_percent' => 90,
        ]]]);
        $bucketId = $allocation->fares->first()->id;
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [
            $this->passengerPayload(['allocation_fare_id' => $bucketId, 'date_of_birth' => now()->subYears(5)->toDateString()]),
            $this->passengerPayload(['allocation_fare_id' => $bucketId, 'infant' => [
                'first_name' => 'Baby', 'last_name' => 'Dorj', 'date_of_birth' => now()->subYear()->toDateString(),
                'document_expires_at' => now()->addYears(4)->toDateString(),
            ]]),
        ]])->assertCreated();

        $fares = PassengerSegment::query()->with('passenger')->get()->mapWithKeys(fn ($s) => [$s->passenger->type->value => $s]);
        $this->assertSame('600000.00', $fares['CHD']->fare_amount);
        $this->assertSame('40000.00', $fares['CHD']->tax_amount);
        $this->assertSame('800000.00', $fares['ADT']->fare_amount);
        $this->assertSame('80000.00', $fares['INF']->fare_amount);
        $this->assertSame('0.00', $fares['INF']->tax_amount);
    }

    public function test_allocation_update_without_fares_keeps_fare_quotas(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight, 5, ['Y' => 5], ['fares' => [
            ['cabin_class' => 'Y', 'seats' => 2, 'fare_amount' => 800000, 'tax_amount' => 40000],
        ]]);
        $this->actingAsRole('airline-admin');

        $this->putJson("/api/v1/admin/allocations/{$allocation->id}", [
            'agency_id' => $this->agency->id, 'outbound_flight_id' => $flight->id, 'seats' => 6,
            'deadline' => $allocation->deadline->toDateString(), 'cabins' => ['Y' => 4, 'C' => 2],
        ])->assertOk()
            ->assertJsonPath('data.uses_fare_quotas', true)
            ->assertJsonCount(1, 'data.fares')
            ->assertJsonPath('data.cabins.C', 2);
    }

    public function test_agency_cannot_touch_another_agencys_booking_and_can_cancel_own(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight);
        $this->actingAsRole('agency-agent');
        $passengerId = $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])
            ->json('data.passengers.0.id');

        $other = Agency::create(['name' => 'Other']);
        $this->actingAsRole('agency-agent', ['agency_id' => $other->id]);
        $this->getJson("/api/v1/agency/passengers/{$passengerId}")->assertForbidden();

        $this->actingAsRole('agency-agent');
        $this->deleteJson("/api/v1/agency/passengers/{$passengerId}")->assertOk();
        $this->assertSame('cancelled', PassengerSegment::withTrashed()->first()->status->value);
    }
}
