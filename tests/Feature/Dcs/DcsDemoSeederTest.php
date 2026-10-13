<?php

namespace Tests\Feature\Dcs;

use App\Enums\FlightStatus;
use App\Models\Airline;
use App\Models\Flight;
use App\Models\PassengerBan;
use App\Models\User;
use Database\Seeders\DcsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DcsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_builds_a_live_departure_board_and_is_repeatable(): void
    {
        $this->seed(DcsDemoSeeder::class);
        $this->seed(DcsDemoSeeder::class); // second run replaces the demo flights

        // 8 on today's board + 4 upcoming flights the demo agencies sell (DemoAgencySeeder)
        $this->assertSame(12, Flight::query()->count());
        $this->assertTrue(Flight::query()->where('status', FlightStatus::CheckinOpen)->exists());
        // the previous run's demo bans are replaced, not stacked (they used to block the next run's check-ins)
        $this->assertSame(2, PassengerBan::query()->count());

        Sanctum::actingAs(User::query()->where('email', 'ops@ubn-airport.mn')->firstOrFail());
        $board = $this->getJson('/api/v1/dcs/flights')->assertOk()->json('data');
        $this->assertCount(12, $board); // the board lists every upcoming flight, the agency sales flights too

        $open = collect($board)->firstWhere('status', 'checkin_open');
        $this->assertGreaterThan(0, $open['passenger_counts']['booked']);
        $this->getJson("/api/v1/dcs/flights/{$open['id']}/passengers")->assertOk();
        $this->getJson("/api/v1/dcs/flights/{$open['id']}/seat-map")->assertOk();

        // each demo agency has quotas with sold bookings on its own airline only, also after the second run
        foreach (['om', 'mr'] as $code) {
            Sanctum::actingAs(User::query()->where('email', "{$code}.agency.agent@demo.flightmode.mn")->firstOrFail());
            $quotas = collect($this->getJson('/api/v1/agency/allocations')->assertOk()->json('data'));
            $this->assertNotEmpty($quotas);
            $airlineId = Airline::query()->where('iata_code', strtoupper($code))->value('id');
            $this->assertSame([$airlineId], $quotas->pluck('airline_id')->unique()->values()->all());
            $this->assertGreaterThan(0, $quotas->sum('usage.used'));
        }
    }
}
