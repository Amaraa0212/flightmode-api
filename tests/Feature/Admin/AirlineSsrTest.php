<?php

namespace Tests\Feature\Admin;

use App\Models\SsrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** The admin SSR page saves the airline's SSRs, and the DCS offers exactly those on the airline's flights. */
class AirlineSsrTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_an_airline_saves_its_ssrs_by_code_and_the_dcs_shows_them(): void
    {
        SsrCode::firstOrCreate(['code' => 'WCHR'], ['name' => 'Wheelchair (ramp)', 'category' => 'Medical']);
        $flight = $this->makeFlight(now()->addHours(3));
        $this->actingAsRole('airline-admin');

        // a code in the catalogue is linked; a new one is added to the catalogue
        $this->postJson('/api/v1/admin/airline-ssrs', ['code' => 'wchr', 'price' => 0])->assertCreated()
            ->assertJsonPath('data.ssr.code', 'WCHR')->assertJsonPath('data.airline_id', $this->airline->id);
        $this->postJson('/api/v1/admin/airline-ssrs', ['code' => 'PETC', 'name' => 'Pet in cabin', 'category' => 'Passenger', 'price' => 50000])
            ->assertCreated()->assertJsonPath('data.ssr.name', 'Pet in cabin');
        $this->assertDatabaseHas('ssr_codes', ['code' => 'PETC', 'category' => 'Passenger']);
        // twice: refused
        $this->postJson('/api/v1/admin/airline-ssrs', ['code' => 'WCHR'])->assertStatus(422)->assertJsonValidationErrors('ssr_code_id');
        // a new code needs a name
        $this->postJson('/api/v1/admin/airline-ssrs', ['code' => 'ZZZZ'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->actingAsRole('station-agent');
        $codes = array_column($this->getJson("/api/v1/dcs/flights/{$flight->id}/ssr-codes")->assertOk()->json('data'), 'code');
        $this->assertSame(['PETC', 'WCHR'], $codes);
    }

    public function test_the_manifest_print_gets_the_flights_seat_map(): void
    {
        $flight = $this->makeFlight(now()->addHours(3));
        $this->actingAsRole('airline-admin');

        $this->getJson("/api/v1/admin/flights/{$flight->id}/documents/seat-map")->assertOk()
            ->assertJsonPath('data.flight_id', $flight->id)->assertJsonStructure(['data' => ['layout', 'rows', 'seats']]);
    }
}
