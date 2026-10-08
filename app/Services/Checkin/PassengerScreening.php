<?php

namespace App\Services\Checkin;

use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerBan;
use Illuminate\Support\Collection;

/**
 * Ban-list screening at check-in. A passenger matches a ban by document number, or by
 * surname + given name + date of birth. Only place that decides "cleared / watch / banned".
 *
 * Bound as a scoped singleton: the bans of an airline are loaded once per request, so the
 * DCS passenger list can screen every row without extra queries.
 */
class PassengerScreening
{
    public const CLEARED = 'cleared';

    public const WATCH = 'watch';

    public const BANNED = 'banned';

    /** @var array<string, Collection<int, PassengerBan>> */
    private array $cache = [];

    /** @var array<int, Flight> */
    private array $flights = [];

    /** Same as screen(), with the flight loaded once per request (list rows). */
    public function screenOnFlight(Passenger $passenger, int $flightId): array
    {
        return $this->screen($passenger, $this->flights[$flightId] ??= Flight::query()->findOrFail($flightId));
    }

    /** @return array{status: string, bans: list<array{id: int, ban_type: string, reason: string, valid_until: ?string}>} */
    public function screen(Passenger $passenger, Flight $flight): array
    {
        $matches = $this->bansFor($flight)->filter(fn (PassengerBan $ban) => $this->matches($ban, $passenger))->values();

        $status = match (true) {
            $matches->contains(fn (PassengerBan $b) => $b->ban_type->blocksCheckin()) => self::BANNED,
            $matches->isNotEmpty() => self::WATCH,
            default => self::CLEARED,
        };

        return [
            'status' => $status,
            'bans' => $matches->map(fn (PassengerBan $b) => [
                'id' => $b->id,
                'ban_type' => $b->ban_type->value,
                'reason' => $b->reason,
                'valid_until' => $b->valid_until?->toDateString(),
            ])->all(),
        ];
    }

    public function isBanned(Passenger $passenger, Flight $flight): bool
    {
        return $this->screen($passenger, $flight)['status'] === self::BANNED;
    }

    private function matches(PassengerBan $ban, Passenger $passenger): bool
    {
        $norm = fn (?string $v) => mb_strtoupper(preg_replace('/\s+/', '', (string) $v));

        if ($ban->document_number && $passenger->document_number
            && $norm($ban->document_number) === $norm($passenger->document_number)) {
            return true;
        }

        return $ban->date_of_birth !== null && $passenger->date_of_birth !== null
            && $norm($ban->last_name) === $norm($passenger->last_name)
            && $norm($ban->first_name) === $norm($passenger->first_name)
            && $ban->date_of_birth->isSameDay($passenger->date_of_birth);
    }

    /** @return Collection<int, PassengerBan> */
    private function bansFor(Flight $flight): Collection
    {
        $key = $flight->airline_id.'|'.$flight->departureAt()->toDateString();

        return $this->cache[$key] ??= PassengerBan::query()
            ->inForce($flight->airline_id, $flight->departureAt())
            ->get();
    }
}
