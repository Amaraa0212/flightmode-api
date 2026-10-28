<?php

namespace Tests\Feature\Documents;

use App\Enums\BookingSource;
use App\Enums\CheckinChannel;
use App\Mail\FlightDocumentMail;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\Booking\BookingService;
use App\Services\Checkin\CheckinService;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class FlightDocumentsTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private Passenger $checkedIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(4));
        $admin = $this->makeUser('system-admin');
        $bookings = app(BookingService::class);

        $booking = $bookings->create(['flight_ids' => [$this->flight->id], 'passengers' => [
            $this->passengerPayload(['first_name' => 'Anu', 'last_name' => 'Bat', 'gender' => 'F',
                'infant' => ['first_name' => 'Tuya', 'last_name' => 'Bat', 'date_of_birth' => now()->subMonths(8)->toDateString(),
                    'document_expires_at' => now()->addYears(4)->toDateString()]]),
            $this->passengerPayload(['first_name' => 'Tomor', 'last_name' => 'Gan', 'date_of_birth' => now()->subYears(7)->toDateString()]),
        ]], $admin, BookingSource::Admin);

        $this->checkedIn = $booking->passengers->firstWhere('first_name', 'ANU');
        $this->flight->update(['status' => 'checkin_open']);
        $this->actingAs($admin);
        app(CheckinService::class)->checkIn($this->checkedIn->segments()->first(), '4A', CheckinChannel::Dcs);
    }

    public function test_pnl_lists_every_confirmed_passenger(): void
    {
        $this->actingAsRole('airline-admin');
        $response = $this->get("/api/v1/admin/flights/{$this->flight->id}/documents/pnl")->assertOk();
        $text = $response->getContent();

        $this->assertStringContainsString('PNL', $text);
        $this->assertStringContainsString('1BAT/ANUMRS', $text);
        $this->assertStringContainsString('1GAN/TOMORMSTR', $text);
        $this->assertStringContainsString('INF', $text);
        $this->assertStringContainsString('ENDPNL', $text);
    }

    public function test_manifest_json_counts_only_checked_in_passengers(): void
    {
        $this->actingAsRole('airline-admin');
        $data = $this->getJson("/api/v1/admin/flights/{$this->flight->id}/documents/manifest")->assertOk()->json('data');

        $this->assertSame('TA101', $data['header']['flight_number']);
        // the checked-in adult and her infant; the not checked-in child is not on the manifest
        $this->assertSame(['ADT' => 1, 'CHD' => 0, 'INF' => 1, 'total' => 2], $data['passengers']['totals']);
        $this->assertSame('4A', collect($data['passengers']['rows'])->firstWhere('type', 'ADT')['seat_number']);
        $this->assertArrayHasKey('balance', $data);

        // fields of the printed (legacy) manifests
        $adult = collect($data['passengers']['rows'])->firstWhere('type', 'ADT');
        $this->assertSame($adult['gender'], $adult['gdr']);
        $this->assertSame('INF', collect($data['passengers']['rows'])->firstWhere('type', 'INF')['gdr']);
        $this->assertArrayHasKey('date_of_birth', $adult);
        $this->assertSame($data['header']['std_time'], $data['header']['departure_time']);
        $this->assertSame(
            collect($data['balance']['rows'])->sum('bags'),
            $data['balance']['totals']['bags']
        );
    }

    public function test_excel_downloads(): void
    {
        $this->actingAsRole('airline-admin');
        foreach (['passengers', 'apis', 'no-show', 'manifest?format=xlsx'] as $doc) {
            $response = $this->get("/api/v1/admin/flights/{$this->flight->id}/documents/{$doc}")->assertOk();
            $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'), $doc);
        }
    }

    public function test_excel_contents(): void
    {
        $this->actingAsRole('airline-admin');

        $manifest = $this->readXlsx($this->get("/api/v1/admin/flights/{$this->flight->id}/documents/manifest?format=xlsx")->getContent());
        $this->assertSame(['Passengers', 'Baggage', 'Balance'], $manifest->getSheetNames());
        $this->assertSame('Name', $manifest->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('BAT/ANU', $manifest->getSheet(0)->getCell('A2')->getValue());

        $apis = $this->readXlsx($this->get("/api/v1/admin/flights/{$this->flight->id}/documents/apis")->getContent())->getSheet(0);
        $this->assertSame('TA101', $apis->getCell('B5')->getValue());
        $this->assertTrue($apis->getCell('A1')->isMergeRangeValueCell());
        $this->assertSame('MNG', $apis->getCell('E7')->getValue());

        $csv = $this->get("/api/v1/admin/flights/{$this->flight->id}/documents/passengers?format=csv")->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('"ANU"', $csv->getContent());
    }

    private function readXlsx(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $content);

        return IOFactory::createReader('Xlsx')->load($path);
    }

    public function test_documents_can_be_emailed(): void
    {
        Mail::fake();
        $this->actingAsRole('airline-admin');
        $this->postJson("/api/v1/admin/flights/{$this->flight->id}/documents/email", ['document' => 'pnl', 'email' => 'ops@example.com'])->assertOk();
        Mail::assertSent(FlightDocumentMail::class);
    }

    public function test_eticket_pdf(): void
    {
        $this->actingAsRole('airline-admin');
        $response = $this->get("/api/v1/admin/passengers/{$this->checkedIn->id}/eticket")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_reports(): void
    {
        $this->actingAsRole('airline-admin');
        $this->getJson('/api/v1/admin/reports/traffic?from='.now()->subDay()->toDateString().'&to='.now()->addDays(2)->toDateString())->assertOk();
        $this->getJson('/api/v1/admin/reports/fare-sales')->assertOk();
        $this->getJson('/api/v1/admin/reports/online-checkin')->assertOk();
        $this->getJson('/api/v1/admin/reports/routes')->assertOk();
        $this->getJson('/api/v1/admin/reports/passenger-search?query=ANU')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_pnl_reconciliation_after_departure(): void
    {
        $checkin = app(CheckinService::class);
        $admin = $this->makeUser('system-admin');
        // a counter go-show who boards and is then taken off at the gate
        $goShow = app(BookingService::class)->create(['flight_ids' => [$this->flight->id], 'passengers' => [
            $this->passengerPayload(['first_name' => 'Bold', 'last_name' => 'Dorj']),
        ]], $admin, BookingSource::Admin)->passengers->first()->segments()->first();
        $goShow->update(['is_go_show' => true]);
        $checkin->checkIn($goShow, '5A', CheckinChannel::Dcs);
        $checkin->board($goShow);
        $checkin->offload($goShow, 'security', 'Late document check');
        $checkin->board($this->checkedIn->segments()->first());

        $this->actingAsRole('airline-admin');
        $url = "/api/v1/admin/flights/{$this->flight->id}/documents/reconciliation";

        // gate still open: the child who has not come yet is "not boarded", not a no-show
        $open = $this->getJson($url)->assertOk()->json('data');
        $this->assertFalse($open['closed']);
        $this->assertSame(['ADT' => 0, 'CHD' => 1, 'INF' => 0, 'total' => 1], $open['summary']['not_boarded']);

        $operations = app(FlightOperationsService::class);
        $operations->closeGate($this->flight->refresh());
        $operations->markDeparted($this->flight->refresh(), null, null, null, null);

        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertTrue($data['closed']);
        $this->assertSame(['ADT' => 1, 'CHD' => 1, 'INF' => 1, 'total' => 2], $data['summary']['pnl']);
        $this->assertSame(['ADT' => 1, 'CHD' => 0, 'INF' => 0, 'total' => 1], $data['summary']['go_show']);
        $this->assertSame(['ADT' => 1, 'CHD' => 0, 'INF' => 1, 'total' => 1], $data['summary']['flown']);
        $this->assertSame(['ADT' => 0, 'CHD' => 1, 'INF' => 0, 'total' => 1], $data['summary']['no_show']);
        $this->assertSame(['ADT' => 1, 'CHD' => 0, 'INF' => 0, 'total' => 1], $data['summary']['offloaded']);
        $this->assertSame(0, $data['summary']['not_boarded']['total']);

        $rows = collect($data['rows'])->keyBy('name');
        $this->assertSame('flown', $rows['BAT/ANU']['result']);
        $this->assertSame('no_show', $rows['GAN/TOMOR']['result']);
        $this->assertSame('offloaded', $rows['DORJ/BOLD']['result']);
        $this->assertTrue($rows['DORJ/BOLD']['go_show']);
        $this->assertSame('Late document check', $rows['DORJ/BOLD']['offload_reason']);

        $xlsx = $this->get("{$url}?format=xlsx")->assertOk();
        $this->assertStringContainsString('pnl-report.xlsx', $xlsx->headers->get('content-disposition'));
    }
}
