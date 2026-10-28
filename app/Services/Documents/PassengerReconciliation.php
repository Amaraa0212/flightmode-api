<?php

namespace App\Services\Documents;

use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Models\Flight;
use App\Models\PassengerSegment;
use Illuminate\Support\Collection;

/**
 * Post-flight reconciliation of the PNL (legacy "PNL report"): who was on the name list and who
 * actually flew — flown, no-show, go-show, offloaded, cancelled — with ADT / CHD / INF totals.
 */
class PassengerReconciliation
{
    public const RESULTS = ['flown', 'no_show', 'offloaded', 'not_boarded', 'cancelled', 'waitlisted'];

    /** @return array{flight: array<string, mixed>, closed: bool, summary: array<string, array<string, int>>, rows: list<array<string, mixed>>} */
    public function build(Flight $flight): array
    {
        $flight->loadMissing(['origin', 'destination']);
        // until the gate closes, a passenger who has not boarded may still come
        $closed = in_array($flight->status, [FlightStatus::GateClosed, FlightStatus::Departed], true);

        $segments = PassengerSegment::query()->with(['passenger.booking.agency'])
            ->where('flight_id', $flight->id)
            ->get();

        $rows = $segments->map(fn (PassengerSegment $segment) => $this->row($segment, $closed))
            ->sortBy([
                fn (array $a, array $b) => array_search($a['result'], self::RESULTS, true) <=> array_search($b['result'], self::RESULTS, true),
                fn (array $a, array $b) => strcmp($a['name'], $b['name']),
            ])
            ->values();

        $listed = $rows->whereNotIn('result', ['cancelled', 'waitlisted']);

        return [
            'flight' => [
                'id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'route' => $flight->route(),
                'departure_at' => $flight->scheduled_departure_at->toIso8601String(),
                'status' => $flight->status->value,
            ],
            'closed' => $closed,
            'summary' => [
                // on the name list before the counter opened (go-shows were added at the counter)
                'pnl' => $this->count($listed->where('go_show', false)),
                'go_show' => $this->count($listed->where('go_show', true)),
                'flown' => $this->count($rows->where('result', 'flown')),
                'no_show' => $this->count($rows->where('result', 'no_show')),
                'offloaded' => $this->count($rows->where('result', 'offloaded')),
                'not_boarded' => $this->count($rows->where('result', 'not_boarded')),
                'cancelled' => $this->count($rows->where('result', 'cancelled')),
            ],
            'rows' => $rows->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(PassengerSegment $segment, bool $closed): array
    {
        $passenger = $segment->passenger;

        return [
            'segment_id' => $segment->id,
            'result' => $this->result($segment, $closed),
            'go_show' => (bool) $segment->is_go_show,
            'name' => $passenger->last_name.'/'.$passenger->first_name,
            'type' => ($passenger->type ?? PassengerType::Adult)->value,
            'pnr' => $passenger->booking?->pnr,
            'ticket_number' => $segment->ticket_number,
            'cabin_class' => $segment->cabin_class->value,
            'seat_number' => $segment->seat_number,
            'agency' => $passenger->booking?->agency?->name,
            'status' => $segment->status->value,
            'checked_in' => $segment->isCheckedIn(),
            'offload_type' => $segment->offload_type,
            'offload_reason' => $segment->offload_reason,
        ];
    }

    private function result(PassengerSegment $segment, bool $closed): string
    {
        return match ($segment->status) {
            SegmentStatus::Boarded, SegmentStatus::Flown => 'flown',
            SegmentStatus::Cancelled => 'cancelled',
            SegmentStatus::Waitlisted => 'waitlisted',
            // checked in or only booked, never boarded
            default => $segment->offload_type ? 'offloaded' : ($closed ? 'no_show' : 'not_boarded'),
        };
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function count(Collection $rows): array
    {
        $counts = ['ADT' => 0, 'CHD' => 0, 'INF' => 0];
        foreach ($rows as $row) {
            $counts[$row['type']]++;
        }

        // seats: infants are counted apart, like FlightStatistics
        return $counts + ['total' => $counts['ADT'] + $counts['CHD']];
    }
}
