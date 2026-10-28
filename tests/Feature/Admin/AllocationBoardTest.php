<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Quota per flight: by-flight view, handing out to several agencies at once, moving seats between agencies. */
class AllocationBoardTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private Agency $second;

    private Agency $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0));
        foreach (['second' => 'Second Travel', 'third' => 'Third Travel'] as $key => $name) {
            $this->{$key} = Agency::create(['name' => $name]);
            $this->{$key}->airlines()->attach($this->airline->id, ['is_active' => true]);
        }
        $this->actingAsRole('airline-admin');
    }

    private function distribute(array $rows, array $remove = [])
    {
        return $this->postJson("/api/v1/admin/flights/{$this->flight->id}/allocations/distribute", ['rows' => $rows, 'remove' => $remove]);
    }

    public function test_a_flight_is_handed_out_to_several_agencies_at_once_and_shown_by_flight(): void
    {
        $data = $this->distribute([
            ['agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 20],
            ['agency_id' => $this->second->id, 'mode' => 'fixed', 'seats' => 10, 'cabins' => ['C' => 2, 'Y' => 8]],
            ['agency_id' => $this->third->id, 'mode' => 'pool', 'seats' => 0],
        ])->assertOk()->json('data');

        $this->assertSame(30, $data['fixed_seats']);
        $this->assertSame(26, $data['free']);                 // 56 seats - 30 fixed
        $this->assertSame(54, $data['given_percent']);
        $this->assertCount(3, $data['allocations']);
        $this->assertSame(26, collect($data['allocations'])->firstWhere('mode', 'pool')['remaining']);

        $board = $this->getJson('/api/v1/admin/allocations-board')->assertOk()->json('data');
        $this->assertCount(1, $board);
        $this->assertSame($this->flight->id, $board[0]['flight']['id']);

        // too much in one go: nothing is saved and the agency is named
        $this->distribute([['agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 60,
            'allocation_id' => collect($data['allocations'])->firstWhere('agency.id', $this->agency->id)['allocation_id']]])
            ->assertStatus(422)->assertJsonPath('code', 'not_enough_seats')->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Test Travel:'));
        $this->assertSame(20, AgencyAllocation::query()->where('agency_id', $this->agency->id)->value('seats'));
    }

    public function test_quota_moves_between_agencies_in_one_request_and_seats_move_between_two(): void
    {
        $data = $this->distribute([
            ['agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 40],
            ['agency_id' => $this->second->id, 'mode' => 'fixed', 'seats' => 8],
        ])->assertOk()->json('data');
        $first = collect($data['allocations'])->firstWhere('agency.id', $this->agency->id)['allocation_id'];
        $second = collect($data['allocations'])->firstWhere('agency.id', $this->second->id)['allocation_id'];

        // the flight is full (48 of 48 economy); giving 10 from the first to the second works because the first shrinks first
        $this->distribute([
            ['allocation_id' => $second, 'agency_id' => $this->second->id, 'mode' => 'fixed', 'seats' => 18],
            ['allocation_id' => $first, 'agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 30],
        ])->assertOk();

        $this->postJson('/api/v1/admin/allocations/move', ['from_allocation_id' => $first, 'to_allocation_id' => $second, 'cabin_class' => 'Y', 'seats' => 5])
            ->assertOk();
        $this->assertSame([25, 23], [AgencyAllocation::find($first)->seats, AgencyAllocation::find($second)->seats]);

        $this->postJson('/api/v1/admin/allocations/move', ['from_allocation_id' => $first, 'to_allocation_id' => $second, 'cabin_class' => 'Y', 'seats' => 26])
            ->assertStatus(422)->assertJsonPath('code', 'not_enough_unsold');

        // removing in the same request
        $this->distribute([], [$second])->assertOk()->assertJsonCount(1, 'data.allocations');
    }
}
