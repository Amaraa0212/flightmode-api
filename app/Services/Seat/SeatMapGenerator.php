<?php

namespace App\Services\Seat;

use App\Enums\CabinClass;
use App\Enums\SeatSide;
use App\Enums\SeatType;
use App\Exceptions\BusinessRuleException;
use App\Models\Aircraft;
use App\Models\AircraftSeat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the physical seat map of an aircraft, one block of rows at a time
 * (e.g. rows 1-3, cabin C, columns "AC" | "" | "DF").
 */
class SeatMapGenerator
{
    /**
     * @param  array{left?: string, middle?: string, right?: string}  $columns  seat letters per side, e.g. ['left' => 'ABC', 'right' => 'DEF']
     * @return Collection<int, AircraftSeat>
     */
    public function generateBlock(
        Aircraft $aircraft,
        int $rowFrom,
        int $rowTo,
        CabinClass $cabin,
        array $columns,
        ?string $zone = null,
        bool $emergencyExit = false,
        array $skipRows = [],
    ): Collection {
        if ($rowFrom < 1 || $rowTo < $rowFrom) {
            throw BusinessRuleException::make('invalid_rows', 'Row range is invalid.');
        }

        $seats = [];
        foreach (range($rowFrom, $rowTo) as $row) {
            if (in_array($row, $skipRows, true)) {
                continue;
            }
            foreach ([SeatSide::Left, SeatSide::Middle, SeatSide::Right] as $side) {
                $letters = str_split(strtoupper(trim($columns[$side->value] ?? '')));
                $letters = array_values(array_filter($letters, fn ($l) => ctype_alpha($l)));
                foreach ($letters as $index => $letter) {
                    $seats[] = [
                        'aircraft_id' => $aircraft->id,
                        'seat_row' => $row,
                        'seat_column' => $letter,
                        'seat_number' => $row.$letter,
                        'cabin_class' => $cabin->value,
                        'seat_type' => $this->seatType($side, $index, count($letters))->value,
                        'side' => $side->value,
                        'zone' => $zone ? strtoupper($zone) : null,
                        'is_emergency_exit' => $emergencyExit,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if ($seats === []) {
            throw BusinessRuleException::make('no_columns', 'At least one seat column is required.');
        }

        $this->assertCabinCapacity($aircraft, $cabin, $rowFrom, $rowTo, count($seats));

        return DB::transaction(function () use ($aircraft, $rowFrom, $rowTo, $seats) {
            $this->assertRowsNotInUse($aircraft, $rowFrom, $rowTo);
            AircraftSeat::query()->where('aircraft_id', $aircraft->id)->whereBetween('seat_row', [$rowFrom, $rowTo])->delete();
            AircraftSeat::query()->insert($seats);

            return AircraftSeat::query()->where('aircraft_id', $aircraft->id)
                ->whereBetween('seat_row', [$rowFrom, $rowTo])->orderBy('seat_row')->orderBy('seat_column')->get();
        });
    }

    public function deleteRows(Aircraft $aircraft, int $rowFrom, int $rowTo): int
    {
        $this->assertRowsNotInUse($aircraft, $rowFrom, $rowTo);

        return AircraftSeat::query()->where('aircraft_id', $aircraft->id)->whereBetween('seat_row', [$rowFrom, $rowTo])->delete();
    }

    /** Window at the fuselage, aisle next to the corridor, middle in between. */
    public function seatType(SeatSide $side, int $index, int $count): SeatType
    {
        $first = $index === 0;
        $last = $index === $count - 1;

        return match ($side) {
            SeatSide::Left => $first ? SeatType::Window : ($last ? SeatType::Aisle : SeatType::Middle),
            SeatSide::Right => $last ? SeatType::Window : ($first ? SeatType::Aisle : SeatType::Middle),
            SeatSide::Middle => ($first || $last) ? SeatType::Aisle : SeatType::Middle,
        };
    }

    private function assertCabinCapacity(Aircraft $aircraft, CabinClass $cabin, int $rowFrom, int $rowTo, int $newSeats): void
    {
        $capacity = $aircraft->cabins()->where('cabin_class', $cabin->value)->value('seat_count');
        if ($capacity === null) {
            throw BusinessRuleException::make('cabin_not_configured', "Cabin {$cabin->value} is not configured on this aircraft — add it to the seat configuration in Fleets (e.g. W16Y120).");
        }

        $existing = AircraftSeat::query()->where('aircraft_id', $aircraft->id)->where('cabin_class', $cabin->value)
            ->whereNotBetween('seat_row', [$rowFrom, $rowTo])->count();

        if ($existing + $newSeats > $capacity) {
            throw BusinessRuleException::make('cabin_capacity_exceeded',
                "Cabin {$cabin->value} allows {$capacity} seats, this map would have ".($existing + $newSeats).'.');
        }
    }

    /** A seat map cannot change under passengers that already hold seats on a future flight. */
    private function assertRowsNotInUse(Aircraft $aircraft, int $rowFrom, int $rowTo): void
    {
        $inUse = DB::table('passenger_segments')
            ->join('flights', 'flights.id', '=', 'passenger_segments.flight_id')
            ->join('aircraft_seats', function ($join) {
                $join->on('aircraft_seats.aircraft_id', '=', 'flights.aircraft_id')
                    ->on('aircraft_seats.seat_number', '=', 'passenger_segments.seat_number');
            })
            ->where('flights.aircraft_id', $aircraft->id)
            ->where('flights.scheduled_departure_at', '>=', now())
            ->whereNull('passenger_segments.deleted_at')
            ->whereBetween('aircraft_seats.seat_row', [$rowFrom, $rowTo])
            ->exists();

        if ($inUse) {
            throw BusinessRuleException::make('seats_in_use', 'Passengers hold seats in these rows on upcoming flights.');
        }
    }
}
