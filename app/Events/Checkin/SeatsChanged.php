<?php

namespace App\Events\Checkin;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Seats of a flight changed, for the public online check-in seat map (`oci.flight.{id}`, no auth).
 * Only seat numbers and states — never who sits where.
 *   occupied  checked in / given at the counter
 *   held      someone is choosing it online
 *   released  freed: the online state depends on zones / exits, so clients reload the seat map
 */
class SeatsChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public const OCCUPIED = 'occupied';

    public const HELD = 'held';

    public const RELEASED = 'released';

    /** @param array<string, string> $seats seat_number => state */
    public function __construct(public readonly int $flightId, public readonly array $seats) {}

    /** Broadcast after the surrounding transaction; a broadcaster that is down never breaks check-in. */
    public static function announce(int $flightId, array $seats): void
    {
        $seats = array_filter($seats, fn ($state, $seat) => $seat !== '', ARRAY_FILTER_USE_BOTH);
        if ($seats !== []) {
            DB::afterCommit(fn () => rescue(fn () => event(new self($flightId, $seats))));
        }
    }

    public function broadcastOn(): Channel
    {
        return new Channel("oci.flight.{$this->flightId}");
    }

    public function broadcastAs(): string
    {
        return 'seats.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'seats' => collect($this->seats)->map(fn ($state, $seat) => ['seat_number' => $seat, 'state' => $state])->values()->all(),
        ];
    }
}
