<?php

namespace Tests\Feature\Agency;

use App\Enums\CabinClass;
use App\Enums\CheckinChannel;
use App\Enums\SegmentStatus;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Airport;
use App\Models\Passenger;
use App\Services\Allocation\AllocationService;
use App\Services\Checkin\CheckinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Agencies set to the Oyu Tolgoi layout upload OT's transport report (made-up people, the real layout). */
class OtPassengerImportTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private AgencyAllocation $allocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->agency->update(['import_format' => Agency::IMPORT_OT]);
        // OT flights are domestic: no passport needed
        $khanbumbat = Airport::create(['iata_code' => 'OTK', 'name' => 'Khanbumbat', 'city' => 'Khanbogd',
            'country_id' => $this->ubn->country_id, 'timezone' => 'Asia/Ulaanbaatar']);
        $this->allocation = $this->makeAllocation($this->makeFlight(null, ['destination_airport_id' => $khanbumbat->id]), seats: 3, cabins: ['Y' => 3]);
        $this->actingAsRole('agency-agent');
    }

    /** @param array<int, array{0: string, 1: ?string, 2?: ?string}> $people name, SAP, mobile */
    private function report(array $people): UploadedFile
    {
        $rows = [
            ['OTUB 2140 E-195 WED 2026-10-28'],
            [],
            [null, 'Departure Date', '28/10/2026'],
            [null, 'Transport Number', 'E-195 WED'],
            [null, 'Number Of Passengers', (string) count($people)],
            [null, 'Direction', 'OUT'],
            [],
            array_fill(0, 11, 'By signing below, I hold the airline responsible for my safety not OT LLC:'),
            ['No', 'Passenger Name', 'Company', 'Department', 'Position', 'Cost Center', 'Signatures', 'C No', 'Pick-up Address', 'Profile SAP', 'Profile Personal Mobile'],
        ];
        foreach ($people as $i => $p) {
            $rows[] = [(string) ($i + 1), $p[0], 'Sandvik Mongolia LLC', 'Mobile Maintenance', 'Mechanic', '49022414-Internal Labour', '', '', '', $p[1], $p[2] ?? '+97699000000'];
        }

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'ot').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, 'OTUB_2140.xlsx', null, null, true);
    }

    private function upload(UploadedFile $file, bool $replace = false)
    {
        return $this->post("/api/v1/agency/allocations/{$this->allocation->id}/import",
            ['file' => $file, 'replace_existing' => $replace ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_reads_the_ot_report(): void
    {
        $this->upload($this->report([
            ['Mr. Aldar Bold', '9592220', '+976 8601-4170'],
            ['Mrs. Saraa Munkh Erdene', '1039571'],
            ['Mr. Bat Dorj', ''], // a local citizen without SAP number
        ]))->assertCreated()
            ->assertJsonPath('data.created', 3)->assertJsonPath('data.kept', 0)->assertJsonCount(3, 'data.bookings');

        $aldar = Passenger::query()->where('person_code', '9592220')->sole();
        $this->assertSame(['ALDAR', 'BOLD', 'M', '+97686014170'], [$aldar->first_name, $aldar->last_name, $aldar->gender?->value, $aldar->phone]);
        $this->assertSame('import', $aldar->booking->source->value);

        $saraa = Passenger::query()->where('person_code', '1039571')->sole();
        $this->assertSame(['SARAA', 'MUNKH ERDENE', 'F'], [$saraa->first_name, $saraa->last_name, $saraa->gender?->value]);
        $this->assertNull(Passenger::query()->where('first_name', 'BAT')->sole()->person_code);
    }

    public function test_sync_keeps_listed_workers_adds_new_and_cancels_the_rest(): void
    {
        $this->upload($this->report([['Mr. Aldar Bold', '9592220'], ['Mr. Bat Dorj', '1111111'], ['Mr. Local Citizen', '']]))->assertCreated();
        $aldar = Passenger::query()->where('person_code', '9592220')->sole();
        $segment = $aldar->segments()->firstOrFail();
        app(CheckinService::class)->checkIn($segment, '5C', CheckinChannel::Online);

        // Bat dropped, Saraa new; Aldar (checked in) and the citizen (no SAP: matched by name) are kept as they are
        $this->upload($this->report([['Mr. Aldar Bold', '9592220'], ['Mr. Local Citizen', ''], ['Ms. Saraa Bold', '2222222']]), replace: true)
            ->assertCreated()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.kept', 2)->assertJsonPath('data.cancelled', 1)
            ->assertJsonPath('message', '1 added, 2 already on the quota, 1 cancelled, 0 waitlisted.');

        $this->assertSame($aldar->booking_id, Passenger::query()->where('person_code', '9592220')->sole()->booking_id);
        $this->assertSame(SegmentStatus::CheckedIn, $segment->fresh()->status);
        $this->assertSoftDeleted(Passenger::withTrashed()->where('person_code', '1111111')->sole());
        $this->assertSame(3, Passenger::query()->whereHas('booking', fn ($q) => $q->where('agency_allocation_id', $this->allocation->id))->count());
    }

    public function test_without_replace_listed_workers_are_not_added_twice(): void
    {
        $this->upload($this->report([['Mr. Aldar Bold', '9592220']]))->assertCreated();

        $this->upload($this->report([['Mr. Aldar Bold', '9592220'], ['Mr. Bat Dorj', '1111111']]))->assertCreated()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.kept', 1)->assertJsonPath('data.cancelled', 0);
        $this->assertSame(1, Passenger::query()->where('person_code', '9592220')->count());
    }

    public function test_past_the_quota_the_rest_are_waitlisted_in_file_order(): void
    {
        $this->allocation->update(['is_waitlist' => true]);
        $this->upload($this->report([
            ['Mr. A One', '1'], ['Mr. B Two', '2'], ['Mr. C Three', '3'], ['Mr. D Four', '4'],
        ]))->assertCreated()->assertJsonPath('data.created', 4)->assertJsonPath('data.waitlisted', 1);

        $this->assertSame('waitlisted', Passenger::query()->where('person_code', '4')->sole()->booking->status->value);
    }

    public function test_a_quota_without_room_is_refused_up_front(): void
    {
        $this->upload($this->report([['Mr. A One', '1'], ['Mr. B Two', '2'], ['Mr. C Three', '3'], ['Mr. D Four', '4']]))
            ->assertStatus(422)->assertJsonPath('code', 'import_no_room')
            ->assertJsonPath('context.free', 3)->assertJsonPath('context.new', 4);
        $this->assertSame(0, Passenger::query()->count());

        // workers already on the quota need no new seat; a dropped one frees its seat (sync)
        $this->upload($this->report([['Mr. A One', '1'], ['Mr. B Two', '2'], ['Mr. C Three', '3']]))->assertCreated();
        $this->upload($this->report([['Mr. A One', '1'], ['Mr. B Two', '2'], ['Mr. D Four', '4']]), replace: true)
            ->assertCreated()->assertJsonPath('data.created', 1)->assertJsonPath('data.cancelled', 1);
        $this->upload($this->report([['Mr. A One', '1'], ['Mr. B Two', '2'], ['Mr. D Four', '4'], ['Mr. E Five', '5']]))
            ->assertStatus(422)->assertJsonPath('context.free', 0)->assertJsonPath('context.new', 1)->assertJsonPath('context.in_file', 4);
    }

    public function test_a_pool_quota_counts_the_seats_other_agencies_hold(): void
    {
        // another agency's fixed quota takes every economy seat of the flight but 2
        $flight = $this->makeFlight(now()->addDays(5), ['destination_airport_id' => $this->allocation->outboundFlight->destination_airport_id]);
        $size = app(AllocationService::class)->cabinAllocationRoom($flight, CabinClass::Economy);
        $other = Agency::create(['name' => 'Other agency', 'code' => 'OTHER']);
        $other->airlines()->attach($this->airline->id, ['is_active' => true]);
        $this->makeAllocation($flight, seats: $size - 2, cabins: ['Y' => $size - 2], extra: ['agency_id' => $other->id]);
        $this->allocation = $this->makeAllocation($flight, seats: 0, cabins: ['Y' => 0], extra: ['mode' => AgencyAllocation::POOL]);

        $this->upload($this->report([['Mr. A One', '1'], ['Mr. B Two', '2'], ['Mr. C Three', '3']]))
            ->assertStatus(422)->assertJsonPath('code', 'import_no_room')->assertJsonPath('context.free', 2)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, "other agencies' fixed quotas"));
    }

    public function test_a_wrong_file_is_refused_and_nothing_is_saved(): void
    {
        $this->upload($this->report([['Mr. Aldar', '1'], ['Mr. Бат Дорж', '2'], ['Mr. Bat Dorj', '1']]))
            ->assertStatus(422)->assertJsonPath('code', 'import_invalid')
            ->assertJsonCount(3, 'context.errors');

        // the standard template is not an OT report
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['FirstName', 'LastName'], ['Bat', 'Dorj']], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'ot').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $this->upload(new UploadedFile($path, 'p.xlsx', null, null, true))->assertStatus(422)->assertJsonPath('code', 'import_not_ot_report');

        $this->assertSame(0, Passenger::query()->count());
    }

    public function test_standard_agencies_keep_the_standard_template(): void
    {
        $this->agency->update(['import_format' => Agency::IMPORT_STANDARD]);

        $this->upload($this->report([['Mr. Aldar Bold', '9592220']]))->assertStatus(422);
    }

    public function test_only_a_system_administrator_sets_the_import_format(): void
    {
        $this->actingAsRole('system-admin');
        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['name' => $this->agency->name, 'import_format' => 'standard'])
            ->assertOk()->assertJsonPath('data.import_format', 'standard');
        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['name' => $this->agency->name, 'import_format' => 'xyz'])
            ->assertStatus(422);

        $this->actingAsRole('airline-admin');
        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['name' => $this->agency->name, 'import_format' => 'ot']);
        $this->assertSame('standard', $this->agency->fresh()->import_format);
    }

    public function test_passport_dates_are_not_checked_for_the_ot_agency(): void
    {
        // an international flight: other agencies must give a passport expiry valid 6 months past departure
        $this->allocation = $this->makeAllocation($this->makeFlight(), seats: 3, cabins: ['Y' => 3]);
        $this->upload($this->report([['Mr. Aldar Bold', '9592220']]))->assertCreated()->assertJsonPath('data.created', 1);

        $this->agency->update(['import_format' => Agency::IMPORT_STANDARD]);
        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->allocation->id,
            'passengers' => [$this->passengerPayload(['document_expires_at' => null])],
        ])->assertStatus(422)->assertJsonPath('code', 'passport_expiry_required');

        $this->agency->update(['import_format' => Agency::IMPORT_OT]);
        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->allocation->id,
            'passengers' => [$this->passengerPayload(['document_expires_at' => now()->addMonth()->toDateString()])],
        ])->assertCreated();
    }
}
