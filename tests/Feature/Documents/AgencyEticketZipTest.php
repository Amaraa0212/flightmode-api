<?php

namespace Tests\Feature\Documents;

use App\Models\Passenger;
use App\Services\Documents\ETicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;
use ZipArchive;

class AgencyEticketZipTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_agency_downloads_the_checked_passengers_tickets_in_one_zip(): void
    {
        $allocation = $this->makeAllocation($this->makeFlight(), 5, ['Y' => 5]);
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [
            $this->passengerPayload(['first_name' => 'Bold', 'last_name' => 'Dorj']),
            $this->passengerPayload(['first_name' => 'Saraa', 'last_name' => 'Bat']),
        ]])->assertCreated();
        $ids = Passenger::query()->pluck('id')->all();

        $response = $this->post('/api/v1/agency/etickets/zip', ['passenger_ids' => $ids, 'lang' => 'mn', 'fare' => 0, 'issued' => 0])
            ->assertOk()->assertHeader('content-type', 'application/zip');

        $path = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values();
        $this->assertCount(2, $names);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}-BAT-SARAA\.pdf$/', $names[0]);
        $this->assertStringStartsWith('%PDF', $zip->getFromIndex(0));
        $zip->close();
        unlink($path);

        $this->postJson('/api/v1/agency/etickets/zip', ['passenger_ids' => [999999]])->assertNotFound();

        // only the agency's own passengers
        $otherAgency = $this->agency->replicate()->fill(['code' => 'OTH', 'name' => 'Other Travel']);
        $otherAgency->save();
        $this->actingAsRole('agency-agent', ['agency_id' => $otherAgency->id]);
        $this->postJson('/api/v1/agency/etickets/zip', ['passenger_ids' => $ids])->assertForbidden();
    }

    public function test_the_issue_date_can_be_left_out(): void
    {
        $passenger = Passenger::query()->first() ?? $this->bookedPassenger();
        $service = app(ETicketService::class);

        $this->assertStringContainsString('Issued', view('documents.eticket', $service->data($passenger))->render());
        $this->assertStringNotContainsString('Issued', view('documents.eticket', $service->data($passenger, 'en', true, false))->render());
    }

    public function test_a_round_trip_quota_prints_both_flights_on_one_eticket(): void
    {
        $out = $this->makeFlight();
        $back = $this->makeFlight(now()->addDays(10)->setTime(9, 0), [
            'flight_number' => 'TA102', 'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id,
        ]);
        $allocation = $this->makeAllocation($out, 5, ['Y' => 5], ['return_flight_id' => $back->id]);
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])
            ->assertCreated();

        $legs = app(ETicketService::class)->data(Passenger::query()->firstOrFail())['legs'];
        $this->assertSame(['TA101', 'TA102'], array_column($legs, 'flight_number'));
        $this->assertSame(['Outbound journey', 'Return journey'], array_column($legs, 'title'));
    }

    private function bookedPassenger(): Passenger
    {
        $allocation = $this->makeAllocation($this->makeFlight(), 5, ['Y' => 5]);
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();

        return Passenger::query()->firstOrFail();
    }
}
