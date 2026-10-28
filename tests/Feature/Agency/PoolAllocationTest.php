<?php

namespace Tests\Feature\Agency;

use App\Enums\CabinClass;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Fare;
use App\Models\Flight;
use App\Services\Allocation\AllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Pool (free sale) allocations: no seats taken out, the chosen agencies sell the flight's free seats together. */
class PoolAllocationTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private Fare $fare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0));
        $this->fare = Fare::create([
            'airline_id' => $this->airline->id, 'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'trip_type' => 'OW', 'cabin_class' => 'Y', 'fare_class' => 'M', 'valid_from' => today(), 'valid_to' => today()->addMonth(),
            'base_amount' => 1000000, 'tax_amount' => 50000,
        ]);
        // a fixed allocation takes 40 of the 48 economy seats: 8 are left for everyone else
        $this->makeAllocation($this->flight, 40, ['Y' => 40]);
    }

    private function poolAgency(string $name, int $limit = 0): AgencyAllocation
    {
        $agency = Agency::create(['name' => $name]);
        $agency->airlines()->attach($this->airline->id, ['is_active' => true]);

        return $this->makeAllocation($this->flight, $limit, ['Y' => 0], ['agency_id' => $agency->id, 'mode' => AgencyAllocation::POOL,
            'fares' => [['fare_id' => $this->fare->id, 'cabin_class' => 'Y', 'seats' => 0]]]);
    }

    private function hold(AgencyAllocation $allocation, int $seats)
    {
        $this->actingAsRole('agency-agent', ['agency_id' => $allocation->agency_id]);

        return $this->postJson('/api/v1/agency/holds', [
            'allocation_id' => $allocation->id, 'cabin_class' => 'Y', 'seats' => $seats, 'allocation_fare_id' => $allocation->fares->first()->id,
        ]);
    }

    private function remaining(AgencyAllocation $allocation): int
    {
        $this->actingAsRole('agency-agent', ['agency_id' => $allocation->agency_id]);

        return $this->getJson("/api/v1/agency/allocations/{$allocation->id}")->assertOk()->json('data.usage.remaining');
    }

    public function test_pool_agencies_take_no_seats_out_and_share_what_is_free(): void
    {
        $service = app(AllocationService::class);
        $a = $this->poolAgency('Pool A');
        $b = $this->poolAgency('Pool B');

        // nothing is taken out by the pool allocations
        $this->assertSame(8, $service->cabinSeatsLeftForAllocations($this->flight, CabinClass::Economy));
        $this->assertSame(8, $this->remaining($a));
        $this->assertSame(8, $this->remaining($b));

        // seats A holds are gone for B
        $holdId = $this->hold($a, 6)->assertCreated()->json('data.id');
        $this->assertSame(2, $this->remaining($b));
        $this->hold($b, 3)->assertStatus(422)->assertJsonPath('code', 'not_enough_seats');
        $this->hold($b, 2)->assertCreated();
        $this->assertSame(0, $this->remaining($b));

        // A names passengers on its own held seats; nobody else can sell
        $this->actingAsRole('agency-agent', ['agency_id' => $a->agency_id]);
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $a->id, 'hold_id' => $holdId,
            'passengers' => [$this->passengerPayload(), $this->passengerPayload(['first_name' => 'Tuya'])]])->assertCreated();
        $this->actingAsRole('agency-agent', ['agency_id' => $b->agency_id]);
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $b->id,
            'passengers' => [$this->passengerPayload(['allocation_fare_id' => $b->fares->first()->id])]])
            ->assertStatus(422)->assertJsonPath('code', 'flight_full');

        // pool sales and holds also leave nothing to give to a new fixed allocation
        $this->assertSame(0, $service->cabinSeatsLeftForAllocations($this->flight->refresh(), CabinClass::Economy));
    }

    public function test_a_pool_agency_sells_up_to_its_limit(): void
    {
        $limited = $this->poolAgency('Pool limited', 3);

        $this->assertSame(3, $this->remaining($limited));
        $this->hold($limited, 4)->assertStatus(422)->assertJsonPath('code', 'not_enough_seats');
        $this->hold($limited, 3)->assertCreated();
        $this->assertSame(0, $this->remaining($limited));
    }

    public function test_admin_creates_a_pool_allocation_without_seats(): void
    {
        $agency = Agency::create(['name' => 'Pool C']);
        $agency->airlines()->attach($this->airline->id, ['is_active' => true]);
        $this->actingAsRole('airline-admin');
        $body = ['agency_id' => $agency->id, 'outbound_flight_id' => $this->flight->id, 'deadline' => today()->addDays(3)->toDateString(),
            'mode' => 'pool', 'seats' => 0];   // no cabins: every cabin of the aircraft

        $this->postJson('/api/v1/admin/allocations', $body)->assertCreated()
            ->assertJsonPath('data.mode', 'pool')->assertJsonPath('data.seats', 0)
            ->assertJsonPath('data.usage.remaining', 16);   // 8 economy + 8 business

        // a fixed allocation still needs seats
        $this->postJson('/api/v1/admin/allocations', ['mode' => 'fixed', 'agency_id' => $this->agency->id, 'cabins' => ['Y' => 0]] + $body)
            ->assertStatus(422)->assertJsonValidationErrors('seats');
    }
}
