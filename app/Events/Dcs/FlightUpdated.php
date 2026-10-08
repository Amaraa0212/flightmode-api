<?php

namespace App\Events\Dcs;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed on a flight that other DCS counters should reload
 * (check-in, seat, baggage, group, passenger, flight status). Sent by ActivityLogger.
 */
class FlightUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $flightId,
        public readonly string $event,
        public readonly ?int $passengerId = null,
        public readonly ?int $userId = null,
        /** Seat taken / released: { segment_id, from, to } — counters repaint the seat map without waiting for a reload */
        public readonly ?array $seat = null,
        /** FlightStatistics::summary after the change — the counters' flight bar figures, no reload needed */
        public readonly ?array $statistics = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("dcs.flight.{$this->flightId}");
    }

    public function broadcastAs(): string
    {
        return 'flight.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'flight_id' => $this->flightId,
            'event' => $this->event,
            'passenger_id' => $this->passengerId,
            'user_id' => $this->userId,
            'seat' => $this->seat,
            'statistics' => $this->statistics,
        ];
    }
}
