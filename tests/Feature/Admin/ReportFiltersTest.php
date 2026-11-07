<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Models\Airline;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class ReportFiltersTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @return list<PassengerSegment> */
    private function book(Flight $flight, int $count = 1): array
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$flight->id], 'passengers' => array_map(fn () => $this->passengerPayload(), range(1, $count))],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );

        return $booking->passengers->map(fn ($p) => $p->segments()->firstOrFail())->all();
    }

    private function range(): string
    {
        return 'from='.today()->subDays(2)->toDateString().'&to='.today()->addMonth()->toDateString();
    }

    public function test_checked_in_passengers_count_as_flown_once_the_gate_closed(): void
    {
        $flight = $this->makeFlight(now()->addDay());
        [$scanned, $unscanned, $absent] = $this->book($flight, 3);
        $scanned->update(['status' => 'flown']);
        $unscanned->update(['status' => 'checked_in']);
        $this->actingAsRole('airline-admin');
        $url = '/api/v1/admin/reports/traffic?'.$this->range();

        // still open: nothing is assumed
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.flown', 1)->assertJsonPath('data.0.not_scanned', 0);

        $flight->update(['status' => 'departed']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.flown', 2)
            ->assertJsonPath('data.0.not_scanned', 1)->assertJsonPath('data.0.no_show', 1);
    }

    public function test_traffic_filters_by_type_and_direction(): void
    {
        $out = $this->makeFlight(now()->addDays(3));
        $back = $this->makeFlight(now()->addDays(4), ['flight_number' => 'TA102', 'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id, 'service_type' => 'charter']);
        $this->actingAsRole('airline-admin');
        $ids = fn (string $query) => collect($this->getJson('/api/v1/admin/reports/traffic?'.$this->range().$query)->assertOk()->json('data'))->pluck('flight_id')->all();

        $this->assertSame([$back->id], $ids('&service_type=charter'));
        $this->assertSame([$out->id], $ids("&origin={$this->ubn->iata_code}&destination={$this->pqc->iata_code}"));
        $this->assertSame([$out->id, $back->id], $ids("&origin={$this->ubn->iata_code}&destination={$this->pqc->iata_code}&round_trip=1"));
        $this->assertSame([$out->id], $ids("&origin={$this->ubn->iata_code}"));
    }

    public function test_sales_summary_leaves_out_charters_cancelled_and_other_airlines(): void
    {
        $scheduled = $this->makeFlight(now()->addDays(3));
        $charter = $this->makeFlight(now()->addDays(4), ['flight_number' => 'TA900', 'service_type' => 'charter']);
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        $foreign = $this->makeFlight(now()->addDays(4), ['flight_number' => 'OT1', 'airline_id' => $other->id]);

        [$sold, $cancelled] = $this->book($scheduled, 2);
        $sold->update(['ticket_number' => '1231111111111', 'fare_amount' => 100000, 'tax_amount' => 5000]);
        $cancelled->update(['ticket_number' => '1231111111112', 'fare_amount' => 100000, 'status' => 'cancelled']);
        $this->book($charter)[0]->update(['ticket_number' => '1231111111113', 'fare_amount' => 500000]);
        $this->book($foreign)[0]->update(['ticket_number' => '4561111111111', 'fare_amount' => 700000]);

        $this->actingAsRole('airline-admin');
        $this->getJson('/api/v1/admin/reports/sales-summary?group=route')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', $this->ubn->iata_code.'-'.$this->pqc->iata_code)
            ->assertJsonPath('data.0.tickets', 1)->assertJsonPath('data.0.total', 105000);
        $this->getJson('/api/v1/admin/reports/sales-summary')->assertJsonPath('data.0.key', 'Direct');
        $this->getJson('/api/v1/admin/reports/sales-summary?group=month')->assertJsonPath('data.0.key', now()->format('Y-m'));

        $this->actingAsRole('agency-agent');
        $this->getJson('/api/v1/admin/reports/sales-summary')->assertForbidden();
    }
}
