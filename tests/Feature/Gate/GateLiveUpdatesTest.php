<?php

namespace Tests\Feature\Gate;

use App\Enums\BookingSource;
use App\Events\Dcs\FlightUpdated;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Gate app live updates: flight channel (manifest, boarding, statistics) and the agent's flight-list channel. */
class GateLiveUpdatesTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(3));
    }

    private function checkedIn(): PassengerSegment
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload()]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );
        $segment = $booking->passengers->first()->segments()->firstOrFail();

        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();

        return $segment;
    }

    /** @return array<string> */
    private function channels(FlightUpdated $event): array
    {
        return array_map(fn ($channel) => $channel->name, $event->broadcastOn());
    }

    public function test_boarding_is_broadcast_to_the_gate_flight_channel_with_statistics(): void
    {
        $segment = $this->checkedIn();
        $gate = $this->actingAsRole('gate-agent');
        $this->flight->gateAgents()->attach($gate->id);
        Event::fake([FlightUpdated::class]);

        $this->postJson("/api/v1/gate/flights/{$this->flight->id}/board", ['barcode' => (string) $segment->id])->assertOk();

        Event::assertDispatched(FlightUpdated::class, fn (FlightUpdated $e) => $e->event === 'passenger.boarded'
            && in_array("private-gate.flight.{$this->flight->id}", $this->channels($e), true)
            && in_array("private-dcs.flight.{$this->flight->id}", $this->channels($e), true)
            // passenger events do not touch the agents' flight lists
            && $e->gateAgentIds === []
            && $e->statistics['boarded']['total'] === 1);
    }

    public function test_a_second_scan_of_a_boarded_pass_is_refused(): void
    {
        $segment = $this->checkedIn();
        $gate = $this->actingAsRole('gate-agent');
        $this->flight->gateAgents()->attach($gate->id);

        $this->postJson("/api/v1/gate/flights/{$this->flight->id}/board", ['barcode' => (string) $segment->id])->assertOk();
        $this->postJson("/api/v1/gate/flights/{$this->flight->id}/board", ['barcode' => (string) $segment->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_boarded')
            ->assertJsonPath('context.boarded_at', $segment->refresh()->boarded_at->toIso8601String());
    }

    public function test_flight_changes_reach_the_flight_lists_of_current_and_removed_gate_agents(): void
    {
        $kept = $this->makeUser('gate-agent');
        $removed = $this->makeUser('gate-agent');
        $added = $this->makeUser('gate-agent');
        $this->flight->gateAgents()->attach([$kept->id, $removed->id]);
        Event::fake([FlightUpdated::class]);

        app(FlightOperationsService::class)->setGateAgents($this->flight, [$kept->id, $added->id]);

        Event::assertDispatched(FlightUpdated::class, function (FlightUpdated $e) use ($kept, $removed, $added) {
            $ids = $e->gateAgentIds;
            sort($ids);
            $expected = [$kept->id, $removed->id, $added->id];
            sort($expected);

            return $e->event === 'flight.gate_agents_changed' && $ids === $expected
                && in_array("private-gate.agent.{$removed->id}", $this->channels($e), true);
        });
    }

    public function test_only_assigned_gate_agents_may_listen(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // channels were registered on the null test driver at boot — register them on reverb
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        $auth = fn (string $channel) => $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);

        $gate = $this->actingAsRole('gate-agent');
        $auth("private-gate.flight.{$this->flight->id}")->assertForbidden();
        $auth("private-gate.agent.{$gate->id}")->assertOk()->assertJsonStructure(['auth']);
        $auth('private-gate.agent.'.($gate->id + 1000))->assertForbidden();

        $this->flight->gateAgents()->attach($gate->id);
        $auth("private-gate.flight.{$this->flight->id}")->assertOk()->assertJsonStructure(['auth']);

        // no gate app, no gate channels — even for its own id
        $agency = $this->actingAsRole('agency-agent');
        $auth("private-gate.agent.{$agency->id}")->assertForbidden();
    }
}
