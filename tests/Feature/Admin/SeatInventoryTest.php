<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Fare;
use App\Models\FareClass;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Models\Rbd;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class SeatInventoryTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private User $admin;

    private Flight $flight;

    /** @var array<string, Rbd> */
    private array $rbds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->admin = $this->actingAsRole('airline-admin');
        $this->flight = $this->makeFlight();

        // Y > B > N > T > V (economy chain), S also under Y; C in business
        foreach ([['Y', null, 1], ['B', 'Y', 2], ['S', 'Y', 2], ['N', 'B', 3], ['T', 'N', 4], ['V', 'T', 5]] as [$code, $parent, $rank]) {
            $this->rbds[$code] = Rbd::create([
                'airline_id' => $this->airline->id, 'code' => $code, 'cabin_class' => 'Y', 'rank' => $rank,
                'parent_id' => $parent ? $this->rbds[$parent]->id : null,
            ]);
        }
        $this->rbds['C'] = Rbd::create(['airline_id' => $this->airline->id, 'code' => 'C', 'cabin_class' => 'C', 'rank' => 1]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/admin/flights/{$this->flight->id}/inventory{$suffix}";
    }

    /** Y 40 seats: Y 40, B 40, S 0, N 20, T 10, V 5 closed; C 8 */
    private function setUpInventory(): void
    {
        $this->postJson($this->url('/initialize'))->assertOk();
        $this->putJson($this->url('/cabins'), ['cabins' => [
            ['cabin_class' => 'C', 'seats' => 8], ['cabin_class' => 'Y', 'seats' => 40],
        ]])->assertOk();
        $this->putJson($this->url('/rbds'), ['rbds' => [
            ['rbd_id' => $this->rbds['Y']->id, 'seats' => 40],
            ['rbd_id' => $this->rbds['B']->id, 'seats' => 40],
            ['rbd_id' => $this->rbds['S']->id, 'seats' => 0],
            ['rbd_id' => $this->rbds['N']->id, 'seats' => 20],
            ['rbd_id' => $this->rbds['T']->id, 'seats' => 10],
            ['rbd_id' => $this->rbds['V']->id, 'seats' => 5, 'is_closed' => true],
        ]])->assertOk();
    }

    private function fare(string $code, bool $waitlist = false): Fare
    {
        $class = FareClass::create([
            'airline_id' => $this->airline->id, 'rbd_id' => $this->rbds[$code]->id, 'booking_code' => $code.'OW', 'waitlist' => $waitlist,
        ]);

        return Fare::create([
            'airline_id' => $this->airline->id, 'fare_class_id' => $class->id,
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'cabin_class' => 'Y', 'fare_class' => $code, 'trip_type' => 'OW',
            'valid_from' => today()->subDay(), 'valid_to' => today()->addMonth(), 'base_amount' => 100000,
        ]);
    }

    private function book(Fare $fare, int $count = 1): void
    {
        $passengers = [];
        for ($i = 0; $i < $count; $i++) {
            $passengers[] = $this->passengerPayload(['fare_id' => $fare->id]);
        }

        app(BookingService::class)->create(['flight_ids' => [$this->flight->id], 'passengers' => $passengers], $this->admin, BookingSource::Admin);
    }

    /** @return array<string, array<string, mixed>> */
    private function rbdRows(): array
    {
        return collect($this->getJson($this->url())->assertOk()->json('data.rbds'))->keyBy('code')->all();
    }

    public function test_initialize_opens_every_class_up_to_the_aircraft_cabin(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.is_controlled', false);

        $data = $this->postJson($this->url('/initialize'))->assertOk()->json('data');

        $this->assertTrue($data['is_controlled']);
        $this->assertSame(['C' => 8, 'Y' => 48], collect($data['cabins'])->pluck('seats', 'cabin_class')->all());
        $rows = collect($data['rbds'])->keyBy('code');
        $this->assertSame(48, $rows['V']['seats']);
        $this->assertSame(48, $rows['V']['available']);
        // tree order: parents first, children by rank
        $this->assertSame(['C', 'Y', 'B', 'N', 'T', 'V', 'S'], collect($data['rbds'])->pluck('code')->all());
        $this->assertSame(3, $rows['T']['level']);

        $this->postJson($this->url('/initialize'))->assertStatus(422)->assertJsonPath('code', 'inventory_exists');
    }

    public function test_sales_reduce_availability_along_the_nesting(): void
    {
        $this->setUpInventory();
        $this->book($this->fare('T'), 4);
        $this->book($this->fare('B'), 2);

        $rows = $this->rbdRows();

        // cabin 40 - 6 sold = 34 for everything that is not limited lower
        $this->assertSame(34, $rows['Y']['available']);
        $this->assertSame(34, $rows['B']['available']);
        $this->assertSame(6, $rows['B']['subtree_sold']);
        // N: 20 - 4 sold below it
        $this->assertSame(16, $rows['N']['available']);
        $this->assertSame(6, $rows['T']['available']);
        $this->assertSame(0, $rows['V']['available'], 'closed');
        $this->assertSame(0, $rows['S']['available'], 'zero seats');
        $this->assertSame(4, $rows['T']['sold']);

        $cabin = collect($this->getJson($this->url())->json('data.cabins'))->firstWhere('cabin_class', 'Y');
        $this->assertSame(6, $cabin['sold']);
        $this->assertSame(34, $cabin['available']);
    }

    public function test_a_sold_out_class_rejects_a_direct_booking_or_waitlists_it(): void
    {
        $this->setUpInventory();
        $t = $this->fare('T');
        $this->book($t, 10);

        $this->assertSame(0, $this->rbdRows()['T']['available']);

        try {
            $this->book($t);
            $this->fail('Booking a sold-out class must fail.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('rbd_sold_out', $e->errorCode);
        }
        $this->assertSame(10, PassengerSegment::query()->where('fare_class', 'T')->count());

        // the higher class N still sells (20 - 10)
        $this->book($this->fare('N'));
        $this->assertSame(9, $this->rbdRows()['N']['available']);

        FareClass::query()->where('booking_code', 'TOW')->update(['waitlist' => true]);
        $this->book($t->refresh());
        $this->assertSame(SegmentStatus::Waitlisted, PassengerSegment::query()->latest('id')->first()->status);
    }

    public function test_flights_without_inventory_sell_as_before(): void
    {
        $this->book($this->fare('V'), 3);

        $this->assertSame(3, PassengerSegment::query()->where('fare_class', 'V')->count());
        $this->assertSame(45, $this->rbdRows()['V']['available']);
    }

    public function test_agency_allocation_seats_are_held_back_but_not_limited_by_classes(): void
    {
        $this->setUpInventory();
        $this->makeAllocation($this->flight, 10, ['Y' => 10]);

        $data = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertSame(30, collect($data['cabins'])->firstWhere('cabin_class', 'Y')['available']);
        $this->assertSame(10, $data['agencies'][0]['available']);
        $this->assertSame(30, collect($data['rbds'])->firstWhere('code', 'Y')['available']);
    }

    public function test_an_inactive_class_stays_in_the_tree_and_closes_the_classes_below_it(): void
    {
        $this->setUpInventory();
        $this->rbds['N']->update(['is_active' => false]);

        $rows = $this->rbdRows();
        $this->assertSame(['C', 'Y', 'B', 'N', 'T', 'V', 'S'], array_keys($rows));
        $this->assertSame(3, $rows['T']['level'], 'still nested under N');
        $this->assertSame(0, $rows['N']['available']);
        $this->assertSame(0, $rows['T']['available']);
        $this->assertSame(40, $rows['B']['available']);
    }

    public function test_agency_allocations_are_limited_by_each_cabin(): void
    {
        $payload = fn (array $cabins) => [
            'agency_id' => $this->agency->id, 'outbound_flight_id' => $this->flight->id,
            'seats' => array_sum($cabins), 'cabins' => $cabins,
            'deadline' => $this->flight->scheduled_departure_at->copy()->subDay()->toDateString(),
        ];

        // aircraft: C 8, Y 48
        $this->postJson('/api/v1/admin/allocations', $payload(['C' => 10]))
            ->assertStatus(422)->assertJsonPath('code', 'not_enough_cabin_seats');

        // with an inventory the cabin is its seats + oversell - curtail
        $this->setUpInventory();
        $this->postJson('/api/v1/admin/allocations', $payload(['Y' => 41]))
            ->assertStatus(422)->assertJsonPath('code', 'not_enough_cabin_seats');
        $id = $this->postJson('/api/v1/admin/allocations', $payload(['C' => 2, 'Y' => 30]))->assertCreated()->json('data.id');

        $cabins = collect($this->getJson($this->url())->json('data.cabins'))->keyBy('cabin_class');
        $this->assertSame(6, $cabins['C']['free_for_allocation']);
        $this->assertSame(10, $cabins['Y']['free_for_allocation']);
        $this->assertSame($id, $this->getJson($this->url())->json('data.agencies.0.allocation_id'));

        // an update does not count the allocation's own seats
        $this->putJson("/api/v1/admin/allocations/{$id}", $payload(['C' => 8, 'Y' => 40]))->assertOk();
        $this->putJson("/api/v1/admin/allocations/{$id}", $payload(['C' => 8, 'Y' => 41]))->assertStatus(422);
    }

    public function test_limits_must_nest(): void
    {
        $this->setUpInventory();

        $this->putJson($this->url('/rbds'), ['rbds' => [['rbd_id' => $this->rbds['T']->id, 'seats' => 25]]])
            ->assertStatus(422)->assertJsonPath('code', 'rbd_exceeds_parent');
        $this->putJson($this->url('/rbds'), ['rbds' => [['rbd_id' => $this->rbds['Y']->id, 'seats' => 41]]])
            ->assertStatus(422)->assertJsonPath('code', 'rbd_exceeds_cabin');
        $this->putJson($this->url('/cabins'), ['cabins' => [['cabin_class' => 'C', 'seats' => 8], ['cabin_class' => 'Y', 'seats' => 49]]])
            ->assertStatus(422)->assertJsonPath('code', 'cabins_exceed_aircraft');

        // oversell lets the root go above the cabin
        $this->putJson($this->url('/cabins'), ['cabins' => [['cabin_class' => 'C', 'seats' => 8], ['cabin_class' => 'Y', 'seats' => 40, 'oversell' => 2]]])->assertOk();
        $this->putJson($this->url('/rbds'), ['rbds' => [['rbd_id' => $this->rbds['Y']->id, 'seats' => 42]]])->assertOk();

        $this->patchJson($this->url("/rbds/{$this->rbds['N']->id}"), ['is_closed' => true])->assertOk()
            ->assertJsonPath('data.rbds.3.code', 'N')->assertJsonPath('data.rbds.3.available', 0)
            ->assertJsonPath('data.rbds.4.available', 0);
    }

    public function test_roll_forward_copies_to_later_flights_and_skips_ones_with_inventory(): void
    {
        $this->setUpInventory();
        $next = $this->makeFlight($this->flight->scheduled_departure_at->copy()->addDay());
        $later = $this->makeFlight($this->flight->scheduled_departure_at->copy()->addDays(2));
        $other = $this->makeFlight($this->flight->scheduled_departure_at->copy()->addDay(), ['flight_number' => 'TA999']);
        $this->postJson("/api/v1/admin/flights/{$later->id}/inventory/initialize")->assertOk();

        $results = $this->postJson($this->url('/roll-forward'), ['date_to' => $later->scheduled_departure_at->copy()->addDay()->toDateString()])
            ->assertOk()->json('data');

        $this->assertSame([$next->id => 'applied', $later->id => 'skipped'], collect($results)->pluck('status', 'flight_id')->all());
        $this->assertSame(20, $next->rbdInventory()->where('rbd_id', $this->rbds['N']->id)->value('seats'));
        $this->assertSame(0, $other->cabinInventory()->count());
        $this->assertSame(48, $later->rbdInventory()->where('rbd_id', $this->rbds['N']->id)->value('seats'));

        $this->postJson($this->url('/roll-forward'), ['date_to' => $later->scheduled_departure_at->copy()->addDay()->toDateString(), 'overwrite' => true])
            ->assertOk()->assertJsonPath('message', 'Copied to 2 of 2 flight(s).');
        $this->assertSame(20, $later->rbdInventory()->where('rbd_id', $this->rbds['N']->id)->value('seats'));
    }

    public function test_templates_are_saved_from_a_flight_and_applied(): void
    {
        $this->setUpInventory();
        $templateId = $this->postJson("/api/v1/admin/inventory-templates/from-flight/{$this->flight->id}", ['name' => 'B737 standard'])
            ->assertCreated()->assertJsonCount(2, 'data.cabins')->assertJsonCount(7, 'data.rbds')->json('data.id');

        $target = $this->makeFlight($this->flight->scheduled_departure_at->copy()->addWeek());
        $this->postJson('/api/v1/admin/inventory/apply-template', ['template_id' => $templateId, 'flight_ids' => [$target->id]])
            ->assertOk()->assertJsonPath('data.0.status', 'applied');
        $this->assertSame(10, $target->rbdInventory()->where('rbd_id', $this->rbds['T']->id)->value('seats'));

        $this->putJson("/api/v1/admin/inventory-templates/{$templateId}", ['rbds' => [['rbd_id' => $this->rbds['Y']->id, 'seats' => 30]]])->assertOk()
            ->assertJsonCount(2, 'data.cabins')->assertJsonCount(1, 'data.rbds');
    }

    public function test_a_flight_series_applies_its_template_to_new_flights(): void
    {
        $this->setUpInventory();
        $templateId = $this->postJson("/api/v1/admin/inventory-templates/from-flight/{$this->flight->id}", ['name' => 'Standard'])
            ->assertCreated()->json('data.id');
        $start = today()->addWeeks(2)->startOfWeek();

        $this->postJson('/api/v1/admin/flight-series', [
            'aircraft_id' => $this->aircraft->id, 'inventory_template_id' => $templateId, 'flight_number' => 'TA777',
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(6)->toDateString(),
            'days_of_week' => [1, 3], 'departure_time' => '09:30', 'arrival_time' => '14:00',
        ])->assertCreated()->assertJsonPath('data.inventory_template_id', $templateId);

        $flights = Flight::query()->where('flight_number', 'TA777')->get();
        $this->assertCount(2, $flights);
        foreach ($flights as $flight) {
            $this->assertSame(40, $flight->cabinInventory()->where('cabin_class', 'Y')->value('seats'));
            $this->assertSame(20, $flight->rbdInventory()->where('rbd_id', $this->rbds['N']->id)->value('seats'));
        }
    }

    public function test_flight_list_shows_inventory_totals_and_staff_cannot_edit(): void
    {
        $this->setUpInventory();
        $this->book($this->fare('B'), 2);

        $row = collect($this->getJson('/api/v1/admin/inventory/flights?from='.today()->toDateString().'&to='.today()->addWeek()->toDateString())
            ->assertOk()->json('data'))->firstWhere('id', $this->flight->id);
        $this->assertSame(['is_controlled' => true, 'capacity' => 56, 'allocated' => 48, 'sold' => 2], $row['inventory']);

        $this->actingAsRole('airline-staff');
        $this->getJson($this->url())->assertOk();
        $this->putJson($this->url('/cabins'), ['cabins' => [['cabin_class' => 'Y', 'seats' => 10]]])->assertForbidden();
    }

    public function test_flight_fares_carry_class_availability(): void
    {
        $this->setUpInventory();
        $this->fare('T');
        $this->fare('V');

        $fares = collect($this->getJson("/api/v1/admin/flights/{$this->flight->id}/fares")->assertOk()->json('data'))->keyBy('fare_class');
        $this->assertSame(10, $fares['T']['available']);
        $this->assertSame(0, $fares['V']['available']);
    }
}
