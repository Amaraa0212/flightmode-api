<?php

namespace App\Services\Seat;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived seat holds while a passenger picks a seat in online check-in,
 * so two people cannot pick the same seat at the same time.
 */
class SeatHoldService
{
    public const TTL_MINUTES = 10;

    public function hold(int $flightId, string $seatNumber, string $holder): bool
    {
        $current = Cache::get($this->key($flightId, $seatNumber));
        if ($current !== null && $current !== $holder) {
            return false;
        }

        Cache::put($this->key($flightId, $seatNumber), $holder, now()->addMinutes(self::TTL_MINUTES));
        $this->remember($flightId, $seatNumber);

        return true;
    }

    public function release(int $flightId, string $seatNumber, ?string $holder = null): void
    {
        $key = $this->key($flightId, $seatNumber);
        if ($holder === null || Cache::get($key) === $holder) {
            Cache::forget($key);
        }
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

    private function remember(int $flightId, string $seatNumber): void
    {
        $seats = Cache::get($this->indexKey($flightId), []);
        $seats[] = $seatNumber;
        Cache::put($this->indexKey($flightId), array_values(array_unique($seats)), now()->addHours(12));
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
