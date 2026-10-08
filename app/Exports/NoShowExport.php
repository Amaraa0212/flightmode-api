<?php

namespace App\Exports;

use App\Enums\SegmentStatus;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Support\Spreadsheet\TableExport;

/** Booked passengers who did not fly (no-show) and counter go-shows of a departed flight. */
class NoShowExport extends TableExport
{
    public function __construct(private readonly Flight $flight) {}

    protected function rows(): iterable
    {
        return PassengerSegment::query()->with(['passenger.booking.agency'])
            ->where('flight_id', $this->flight->id)
            ->where(fn ($q) => $q->whereIn('status', [SegmentStatus::Booked, SegmentStatus::CheckedIn])->orWhere('is_go_show', true))
            ->get()
            ->map(fn (PassengerSegment $segment) => $this->row($segment));
    }

    protected function headings(): array
    {
        return ['Result', 'Name', 'PNR', 'Ticket', 'Cabin', 'Agency', 'Status'];
    }

    /** @return array<int, mixed> */
    private function row(PassengerSegment $segment): array
    {
        return [
            $segment->is_go_show ? 'GO-SHOW' : 'NO-SHOW',
            $segment->passenger->last_name.'/'.$segment->passenger->first_name,
            $segment->passenger->booking->pnr,
            $segment->ticket_number,
            $segment->cabin_class->value,
            $segment->passenger->booking->agency?->name,
            $segment->status->value,
        ];
    }
}
