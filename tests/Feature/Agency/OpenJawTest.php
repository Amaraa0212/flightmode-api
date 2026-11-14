<?php

namespace Tests\Feature\Agency;

use App\Enums\SegmentStatus;
use App\Models\AgencyAccountTransaction;
use App\Models\AgencyAllocation;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\Passenger;
use App\Services\Documents\ETicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class OpenJawTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Airline $other;

    private AgencyAllocation $out;

    private AgencyAllocation $back;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA', 'numeric_code' => '456']);
        $this->agency->airlines()->attach($this->other->id, ['is_active' => true, 'can_import_passengers' => true]);

        // out on Test Air UBN → PQC, back on Other Air PQC → UBN a week later, both charters
        $outbound = $this->makeFlight(null, ['service_type' => 'charter']);
        $return = $this->makeFlight(now()->addDays(10)->setTime(9, 0), [
            'airline_id' => $this->other->id, 'flight_number' => 'OA202', 'service_type' => 'charter',
            'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id,
        ]);
        $this->out = $this->makeAllocation($outbound, 10, ['Y' => 10]);
        $this->back = $this->makeAllocation($return, 10, ['Y' => 10], ['airline_id' => $this->other->id]);
    }

    private function book(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/agency/bookings', $extra + [
            'allocation_id' => $this->out->id,
            'return_allocation_id' => $this->back->id,
            'passengers' => [$this->passengerPayload(['first_name' => 'Bold', 'last_name' => 'Dorj']), $this->passengerPayload(['first_name' => 'Saraa', 'last_name' => 'Dorj'])],
        ]);
    }

    public function test_without_open_jaw_the_other_airline_is_neither_offered_nor_booked(): void
    {
        $this->actingAsRole('agency-agent');
        $this->assertSame([], $this->getJson("/api/v1/agency/allocations/{$this->out->id}/return-options")->assertOk()->json('data'));
        $this->book()->assertStatus(422)->assertJsonPath('code', 'open_jaw_not_allowed');
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_an_open_jaw_agency_books_two_linked_free_bookings_ticketed_at_once(): void
    {
        $this->agency->update(['open_jaw' => true]);
        $this->actingAsRole('agency-agent');
        $this->getJson("/api/v1/agency/allocations/{$this->out->id}/return-options")->assertOk()
            ->assertJsonPath('data.0.id', $this->back->id)->assertJsonPath('data.0.airline.name', 'Other Air');

        $this->book(['on_hold' => true])->assertStatus(422)->assertJsonPath('code', 'open_jaw_no_hold');
        $this->book()->assertCreated();

        [$first, $second] = Booking::query()->orderBy('id')->get()->all();
        $this->assertSame([$this->airline->id, $this->other->id], [$first->airline_id, $second->airline_id]);
        $this->assertSame([$second->id, $first->id], [$first->linked_booking_id, $second->linked_booking_id]);
        $this->assertNotSame($first->pnr, $second->pnr);

        $bold = Passenger::query()->where('booking_id', $first->id)->where('first_name', 'BOLD')->sole();
        $boldBack = $bold->linkedPassenger;
        $this->assertSame([$second->id, 'BOLD'], [$boldBack->booking_id, $boldBack->first_name]);
        $this->assertStringStartsWith('123', $bold->segments->first()->ticket_number);       // each airline's own tickets
        $this->assertStringStartsWith('456', $boldBack->segments->first()->ticket_number);
        $this->assertSame('0.00', $bold->segments->first()->fare_amount);                    // charters: nothing priced
        $this->assertSame(0, AgencyAccountTransaction::query()->count());

        // one e-ticket with both legs, both PNRs and each leg's own airline
        $data = app(ETicketService::class)->data($bold->fresh());
        $this->assertSame(['TA101', 'OA202'], array_column($data['legs'], 'flight_number'));
        $this->assertSame("{$first->pnr} / {$second->pnr}", $data['pnr']);
        $this->assertStringContainsString('Other Air', $data['legs'][1]['operated']);
        $this->assertSame('Outbound journey', $data['legs'][0]['title']);

        // a leg is cancelled on its own: the other one stays
        $this->deleteJson("/api/v1/agency/passengers/{$bold->id}")->assertOk();
        $this->assertSame(SegmentStatus::Booked, $boldBack->fresh()->segments->first()->status);
        $this->assertSame(['OA202'], array_column(app(ETicketService::class)->data($boldBack->fresh())['legs'], 'flight_number'));
    }

    public function test_an_edit_or_a_new_passenger_goes_to_both_linked_bookings(): void
    {
        $this->agency->update(['open_jaw' => true]);
        $this->actingAsRole('agency-agent');
        $this->book()->assertCreated();
        [$first, $second] = Booking::query()->orderBy('id')->get()->all();
        $bold = Passenger::query()->where('booking_id', $first->id)->where('first_name', 'BOLD')->sole();

        $this->putJson("/api/v1/agency/passengers/{$bold->id}", ['first_name' => 'Boldoo', 'document_number' => 'E7777777'])->assertOk();
        $back = $bold->linkedPassenger()->first();
        $this->assertSame(['BOLDOO', 'E7777777'], [$back->first_name, $back->document_number]);

        $this->postJson("/api/v1/agency/bookings/{$first->id}/passengers", ['passengers' => [
            $this->passengerPayload(['first_name' => 'Tuya', 'last_name' => 'Dorj']),
        ]])->assertOk();
        $tuya = Passenger::query()->where('booking_id', $first->id)->where('first_name', 'TUYA')->sole();
        $this->assertSame($second->id, $tuya->linkedPassenger->booking_id);
        $this->assertSame(['TA101', 'OA202'], array_column(app(ETicketService::class)->data($tuya->fresh())['legs'], 'flight_number'));
    }

    public function test_open_jaw_is_for_charters_landing_where_the_trip_started(): void
    {
        $this->agency->update(['open_jaw' => true]);
        $this->actingAsRole('agency-agent');
        $this->back->outboundFlight->update(['destination_airport_id' => $this->pqc->id, 'origin_airport_id' => $this->ubn->id]);
        $this->book()->assertStatus(422)->assertJsonPath('code', 'open_jaw_route');

        $this->back->outboundFlight->update(['origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id, 'service_type' => 'scheduled']);
        $this->book()->assertStatus(422)->assertJsonPath('code', 'open_jaw_charter_only');
    }

    public function test_the_excel_import_puts_every_name_on_the_return_too(): void
    {
        $this->agency->update(['open_jaw' => true]);
        $this->actingAsRole('agency-agent');
        $expires = now()->addYears(3)->toDateString();
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['firstname', 'lastname', 'sex', 'dob', 'nationality', 'passport_number', 'issued_state', 'expire_date', 'booking_group'],
            ['Bat', 'Dorj', 'M', '1990-05-01', 'MN', 'E1111111', 'MN', $expires, 'A'],
            ['Saraa', 'Dorj', 'F', '1992-03-04', 'MN', 'E2222222', 'MN', $expires, 'A'],
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'oj').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->post("/api/v1/agency/allocations/{$this->out->id}/import", [
            'file' => new UploadedFile($path, 'p.xlsx', null, null, true), 'return_allocation_id' => $this->back->id,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.created', 2);

        $this->assertSame(2, Booking::query()->count());   // one group: one trip, two airlines
        $this->assertSame(2, Passenger::query()->where('booking_id', Booking::query()->where('airline_id', $this->other->id)->value('id'))->count());
    }

    public function test_only_a_system_administrator_switches_open_jaw_on(): void
    {
        $this->actingAsRole('airline-admin');
        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['chinggis_sync' => false, 'open_jaw' => true]);
        $this->assertFalse($this->agency->fresh()->open_jaw);

        $this->actingAsRole('system-admin');
        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['open_jaw' => true])->assertOk()->assertJsonPath('data.open_jaw', true);
    }
}
