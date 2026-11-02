<?php

namespace Tests\Feature\Agency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AllocationListTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
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
