<?php

namespace App\Services\Seat;

use App\Events\Checkin\SeatsChanged;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived seat holds while a passenger picks a seat in online check-in,
 * so two people cannot pick the same seat at the same time.
 * The hold key itself is the source of truth (Cache::add is atomic); the per-flight index only lists
 * which keys to look at when drawing the seat map.
 */
class SeatHoldService
{
    public const TTL_MINUTES = 10;

    public function hold(int $flightId, string $seatNumber, string $holder): bool
    {
        $key = $this->key($flightId, $seatNumber);
        $ttl = now()->addMinutes(self::TTL_MINUTES);

        if (! Cache::add($key, $holder, $ttl)) {
            if (Cache::get($key) !== $holder) {
                return false;
            }
            Cache::put($key, $holder, $ttl); // own hold: just extend it

            return true;
        }

        $this->remember($flightId, $seatNumber);
        SeatsChanged::announce($flightId, [$seatNumber => SeatsChanged::HELD]);

        return true;
    }

    /** @param bool $announce false when the seat is being taken right away (check-in announces it as occupied) */
    public function release(int $flightId, string $seatNumber, ?string $holder = null, bool $announce = true): void
    {
        $key = $this->key($flightId, $seatNumber);
        $current = Cache::get($key);
        if ($current === null || ($holder !== null && $current !== $holder)) {
            return;
        }

        Cache::forget($key);
        if ($announce) {
            SeatsChanged::announce($flightId, [$seatNumber => SeatsChanged::RELEASED]);
        }
    }

    /** Who holds the seat right now (null = nobody). */
    public function holder(int $flightId, string $seatNumber): ?string
    {
        return Cache::get($this->key($flightId, $seatNumber));
    }

    /** @return array<string, string> seat_number => holder, for holds that are still alive */
    public function holds(int $flightId): array
    {
        $holds = [];
        foreach (Cache::get($this->indexKey($flightId), []) as $seat) {
            $holder = Cache::get($this->key($flightId, $seat));
            if ($holder !== null) {
                $holds[$seat] = $holder;
            }
        }

        return $holds;
    }

    /** Read-modify-write of the index under a lock, so two holds at once do not drop each other. */
    private function remember(int $flightId, string $seatNumber): void
    {
        Cache::lock($this->indexKey($flightId).':lock', 5)->block(3, function () use ($flightId, $seatNumber) {
            $seats = Cache::get($this->indexKey($flightId), []);
            $seats[] = $seatNumber;
            Cache::put($this->indexKey($flightId), array_values(array_unique($seats)), now()->addHours(12));
        });
    }

    private function key(int $flightId, string $seatNumber): string
    {
        return "seat-hold:{$flightId}:{$seatNumber}";
    }

    private function indexKey(int $flightId): string
    {
        return "seat-hold-index:{$flightId}";
    }
}
