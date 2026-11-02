<?php

namespace Tests\Feature\Agency;

use App\Models\Agency;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** An agency sees the schedule changes of its allocated flights until it acknowledges them. */
class ScheduleChangesTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_a_retimed_flight_shows_on_the_agency_allocation_until_seen(): void
    {
        $flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0));
        $allocation = $this->makeAllocation($flight);
        $before = $flight->scheduled_departure_at->copy();

        $this->travel(1)->minutes();
        // a gate change is not a schedule change
        app(FlightOperationsService::class)->update($flight->fresh(), ['gate' => '7']);
        app(FlightOperationsService::class)->update($flight->fresh(), [
            'scheduled_departure_at' => $before->copy()->addHours(2),
            'scheduled_arrival_at' => $flight->scheduled_arrival_at->copy()->addHours(2),
        ]);

        $this->actingAsRole('agency-agent');
        $row = collect($this->getJson('/api/v1/agency/allocations')->assertOk()->json('data'))->firstWhere('id', $allocation->id);
        $this->assertSame(1, $row['schedule_changes']['unseen']);
        $change = $row['schedule_changes']['changes'][0];
        $this->assertSame('outbound', $change['leg']);
        $this->assertEqualsCanonicalizing(['scheduled_departure_at', 'scheduled_arrival_at'], array_column($change['changes'], 'field'));
        $departure = collect($change['changes'])->firstWhere('field', 'scheduled_departure_at');
        $this->assertSame($before->timestamp, now()->parse($departure['from'])->timestamp);
        $this->assertSame($before->copy()->addHours(2)->timestamp, now()->parse($departure['to'])->timestamp);

        $this->postJson("/api/v1/agency/allocations/{$allocation->id}/schedule-changes/seen")->assertOk();
        $this->getJson("/api/v1/agency/allocations/{$allocation->id}")->assertOk()
            ->assertJsonPath('data.schedule_changes.unseen', 0)
            ->assertJsonPath('data.schedule_changes.changes.0.seen', true);
    }

    public function test_another_agency_cannot_acknowledge(): void
    {
        $allocation = $this->makeAllocation($this->makeFlight());
        $other = Agency::create(['name' => 'Other Travel']);
        $this->actingAsRole('agency-agent', ['agency_id' => $other->id]);

        $this->postJson("/api/v1/agency/allocations/{$allocation->id}/schedule-changes/seen")->assertForbidden();
    }

    public function test_allocations_of_a_deleted_flight_are_left_out_of_the_lists(): void
    {
        $kept = $this->makeAllocation($this->makeFlight(now()->subDays(2)));
        $gone = $this->makeAllocation($this->makeFlight(now()->subDays(3)));
        $gone->outboundFlight->delete();

        $this->actingAsRole('airline-admin');
        $ids = collect($this->getJson('/api/v1/admin/allocations?active=0')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($kept->id, $ids);
        $this->assertNotContains($gone->id, $ids);

        $this->actingAsRole('agency-agent');
        $ids = collect($this->getJson('/api/v1/agency/allocations?status=past')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($kept->id, $ids);
        $this->assertNotContains($gone->id, $ids);
    }
}
