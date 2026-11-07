<?php

namespace Tests\Feature\Agency;

use App\Enums\CabinClass;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Fare;
use App\Models\FareClass;
use App\Models\FareFamily;
use App\Models\Flight;
use App\Models\FlightSeries;
use App\Models\PassengerSegment;
use App\Models\Rbd;
use App\Services\Allocation\AllocationService;
use App\Services\Fare\FareQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/**
 * Scheduled flight split between a fixed block for one agency and a pool for the others:
 * the pool sells at published fares (fare settings) within the booking class limits,
 * fixed sales do not use those limits, unsold fixed seats go back at the names deadline.
 */
class PublishedFareAllocationTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private Rbd $m;

    private Fare $fare;

    private AgencyAllocation $fixed;

    private AgencyAllocation $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
        $this->flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0));

        // booking classes: Y (root) > M, M limited to 2 seats; M sold under fare family FLEX
        $family = FareFamily::create(['airline_id' => $this->airline->id, 'code' => 'FLEX', 'name' => 'Flex']);
        $y = Rbd::create(['airline_id' => $this->airline->id, 'code' => 'Y', 'cabin_class' => 'Y', 'rank' => 1]);
        $this->m = Rbd::create(['airline_id' => $this->airline->id, 'code' => 'M', 'cabin_class' => 'Y', 'rank' => 2,
            'parent_id' => $y->id, 'fare_family_id' => $family->id]);
        $inventory = "/api/v1/admin/flights/{$this->flight->id}/inventory";
        $this->postJson("{$inventory}/initialize")->assertOk();
        $this->putJson("{$inventory}/cabins", ['cabins' => [['cabin_class' => 'C', 'seats' => 8], ['cabin_class' => 'Y', 'seats' => 48]]])->assertOk();
        $this->putJson("{$inventory}/rbds", ['rbds' => [['rbd_id' => $y->id, 'seats' => 48], ['rbd_id' => $this->m->id, 'seats' => 2]]])->assertOk();

        $class = $this->postJson('/api/v1/admin/fare-classes', ['rbd_id' => $this->m->id, 'booking_code' => 'MOW', 'status' => 'HK', 'trip_type' => 'OW'])
            ->assertCreated()->json('data.id');
        $this->fare = Fare::query()->findOrFail($this->postJson('/api/v1/admin/fares', [
            'fare_class_id' => $class, 'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'valid_from' => today()->toDateString(), 'valid_to' => today()->addMonth()->toDateString(), 'base_amount' => 500000,
        ])->assertCreated()->json('data.id'));

        // agency A: fixed C 4 / Y 10; another agency: pool at published fares
        $this->fixed = $this->makeAllocation($this->flight, 14, ['C' => 4, 'Y' => 10]);
        $other = Agency::create(['name' => 'Pool Travel']);
        $other->airlines()->attach($this->airline->id, ['is_active' => true]);
        $this->pool = $this->makeAllocation($this->flight, 0, [], ['agency_id' => $other->id, 'mode' => AgencyAllocation::POOL, 'uses_published_fares' => true]);
    }

    private function bookPool(array $extra = [])
    {
        $this->actingAsRole('agency-agent', ['agency_id' => $this->pool->agency_id]);

        return $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->pool->id,
            'passengers' => [$this->passengerPayload(['fare_id' => $this->fare->id] + $extra)],
        ]);
    }

    public function test_pool_lists_published_fares_and_books_within_the_booking_class_limit(): void
    {
        $this->actingAsRole('agency-agent', ['agency_id' => $this->pool->agency_id]);
        $fares = $this->getJson('/api/v1/agency/flights?date='.$this->flight->scheduled_departure_at->timezone('Asia/Ulaanbaatar')->toDateString())
            ->assertOk()->json('data.0.usage.fares');
        $this->assertCount(1, $fares);
        $this->assertSame('MOW', $fares[0]['fare_class']);
        $this->assertSame('FLEX', $fares[0]['fare_family']['code']);
        $this->assertSame(2, $fares[0]['remaining']);
        $this->assertTrue($fares[0]['published']);

        $this->bookPool()->assertCreated();
        $segment = PassengerSegment::query()->latest('id')->firstOrFail();
        $this->assertSame('M', $segment->fare_class);
        $this->assertSame($this->fare->id, $segment->fare_id);
        $this->assertSame('500000.00', $segment->fare_amount);

        $this->bookPool()->assertCreated();
        // M had 2 seats: the pool cannot sell a third
        $this->bookPool()->assertStatus(422)->assertJsonPath('code', 'rbd_sold_out');
        // a published-fare allocation needs a fare
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $this->pool->id, 'passengers' => [$this->passengerPayload()]])
            ->assertStatus(422)->assertJsonPath('code', 'fare_required');
    }

    public function test_a_pool_hold_keeps_its_published_fare_for_the_names(): void
    {
        $this->actingAsRole('agency-agent', ['agency_id' => $this->pool->agency_id]);
        $hold = fn (int $seats, array $extra = []) => $this->postJson('/api/v1/agency/holds',
            ['allocation_id' => $this->pool->id, 'cabin_class' => 'Y', 'seats' => $seats] + $extra);

        $hold(1)->assertStatus(422)->assertJsonPath('code', 'fare_required');
        // M has 2 seats
        $hold(3, ['fare_id' => $this->fare->id])->assertStatus(422)->assertJsonPath('code', 'rbd_sold_out');
        $id = $hold(2, ['fare_id' => $this->fare->id])->assertCreated()
            ->assertJsonPath('data.fare.fare_class', 'MOW')->json('data.id');

        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->pool->id, 'hold_id' => $id, 'passengers' => [$this->passengerPayload()],
        ])->assertCreated();
        $this->assertSame($this->fare->id, PassengerSegment::query()->latest('id')->value('fare_id'));
    }

    public function test_fixed_sales_do_not_use_the_booking_class_limit(): void
    {
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->fixed->id, 'passengers' => [$this->passengerPayload(['fare_id' => $this->fare->id])],
        ])->assertCreated();
        $this->assertSame('M', PassengerSegment::query()->latest('id')->value('fare_class'));

        // a fixed block lists the fare without booking class seats (its own quota limits it)
        $this->assertNull(app(FareQuoteService::class)->forAllocation($this->fixed->refresh())[0]['remaining']);

        // the fixed block sold one M: the pool still has both M seats
        $this->bookPool()->assertCreated();
        $this->bookPool()->assertCreated();
    }

    public function test_fare_rules_close_the_fare(): void
    {
        // flight in 5 days, booking class needs 10 days advance purchase
        $this->m->update(['advance_booking_days' => 10]);
        $this->bookPool()->assertStatus(422)->assertJsonPath('code', 'fare_not_bookable');
        $this->actingAsRole('agency-agent', ['agency_id' => $this->pool->agency_id]);
        $this->assertSame([], app(FareQuoteService::class)->forAllocation($this->pool->refresh()));

        // an upgrade class is never sold
        $this->m->update(['advance_booking_days' => null, 'is_upgrade' => true]);
        $this->bookPool()->assertStatus(422)->assertJsonPath('code', 'fare_not_bookable');

        $this->m->update(['is_upgrade' => false]);
        $this->bookPool()->assertCreated();
    }

    public function test_return_fare_must_combine_with_the_outbound_fare(): void
    {
        $back = $this->makeFlight(now()->addDays(9)->setTime(8, 0), ['flight_number' => 'TA102',
            'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id]);
        $backClass = FareClass::create(['airline_id' => $this->airline->id, 'rbd_id' => $this->m->id, 'booking_code' => 'MBK', 'trip_type' => 'OW']);
        $backFare = Fare::create(['airline_id' => $this->airline->id, 'fare_class_id' => $backClass->id, 'origin_airport_id' => $this->pqc->id,
            'destination_airport_id' => $this->ubn->id, 'trip_type' => 'OW', 'cabin_class' => 'Y', 'fare_class' => 'M',
            'valid_from' => today(), 'valid_to' => today()->addMonth(), 'base_amount' => 400000, 'tax_amount' => 0]);
        $returnPool = $this->makeAllocation($back, 0, [], ['agency_id' => $this->pool->agency_id, 'mode' => AgencyAllocation::POOL, 'uses_published_fares' => true]);

        $this->actingAsRole('agency-agent', ['agency_id' => $this->pool->agency_id]);
        $book = fn () => $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->pool->id, 'return_allocation_id' => $returnPool->id,
            'passengers' => [$this->passengerPayload(['fare_id' => $this->fare->id, 'return_fare_id' => $backFare->id])],
        ]);

        $book()->assertStatus(422)->assertJsonPath('code', 'fares_not_combinable');

        $this->actingAsRole('airline-admin');
        $this->postJson('/api/v1/admin/fare-combinations', ['fare_class_id' => $this->fare->fare_class_id, 'combinable_fare_class_ids' => [$backClass->id]])
            ->assertCreated();
        $this->actingAsRole('agency-agent', ['agency_id' => $this->pool->agency_id]);
        $book()->assertCreated();
        $this->assertSame(['500000.00', '400000.00'], PassengerSegment::query()->orderBy('segment_no')->pluck('fare_amount')->all());
    }

    public function test_unsold_fixed_seats_go_back_at_the_names_deadline(): void
    {
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $this->fixed->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();

        $service = app(AllocationService::class);
        $before = $service->cabinSeatsLeftForAllocations($this->flight, CabinClass::Economy);

        $this->assertSame(0, $service->releaseUnsold(), 'names still open');
        $this->fixed->update(['deadline' => today()->subDay()]);
        $this->assertSame(1, $service->releaseUnsold());

        $this->fixed->refresh()->load('cabins');
        $this->assertSame(1, $this->fixed->seats);
        $this->assertSame(13, $this->fixed->released_seats);
        $this->assertSame(1, $this->fixed->cabins->firstWhere('cabin_class.value', 'Y')->seats);
        $this->assertSame(0, $this->fixed->cabins->firstWhere('cabin_class.value', 'C')->seats);
        // the 9 unsold economy seats are free for the pool again
        $this->assertSame($before + 9, $service->cabinSeatsLeftForAllocations($this->flight, CabinClass::Economy));
        $this->assertSame(0, $service->releaseUnsold(), 'released once');
    }

    public function test_series_distribution_sets_every_flight_and_reports_failures(): void
    {
        $series = FlightSeries::create([
            'airline_id' => $this->airline->id, 'flight_number' => 'TA101', 'service_type' => 'charter', 'origin_airport_id' => $this->ubn->id,
            'destination_airport_id' => $this->pqc->id, 'departure_time' => '08:00', 'arrival_time' => '14:00',
            'days_of_week' => '1234567', 'period_start' => today(), 'period_end' => today()->addMonth(),
        ]);
        $flights = collect([6, 7, 8])->map(fn ($d) => $this->makeFlight(now()->addDays($d)->setTime(8, 0), ['flight_series_id' => $series->id]));
        // a flight already full of fixed seats cannot take agency A's block
        $this->makeAllocation($flights[2], 48, ['Y' => 48], ['agency_id' => $this->pool->agency_id]);

        $local = fn (Flight $f) => $f->scheduled_departure_at->copy()->timezone('Asia/Ulaanbaatar')->toDateString();
        $this->actingAsRole('airline-admin');
        $result = $this->postJson("/api/v1/admin/flight-series/{$series->id}/allocations/distribute", [
            'date_from' => $local($flights[0]), 'date_to' => $local($flights[2]), 'deadline_days_before' => 2,
            'rows' => [['agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 14, 'cabins' => ['C' => 4, 'Y' => 10]]],
        ])->assertOk()->json('data');

        $this->assertSame(3, $result['flights']);
        $this->assertSame(2, $result['saved']);
        $this->assertSame($flights[2]->id, $result['failed'][0]['flight_id']);

        $allocation = AgencyAllocation::query()->where('outbound_flight_id', $flights[0]->id)->where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame(14, $allocation->seats);
        $this->assertSame(today()->addDays(4)->toDateString(), $allocation->deadline->toDateString());

        // running it again updates instead of duplicating
        $this->postJson("/api/v1/admin/flight-series/{$series->id}/allocations/distribute", [
            'date_from' => $local($flights[0]), 'date_to' => $local($flights[1]),
            'rows' => [['agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 12, 'cabins' => ['C' => 2, 'Y' => 10]]],
        ])->assertOk();
        $this->assertSame(1, AgencyAllocation::query()->where('outbound_flight_id', $flights[0]->id)->where('agency_id', $this->agency->id)->count());
        $this->assertSame(12, $allocation->refresh()->seats);
    }
}
