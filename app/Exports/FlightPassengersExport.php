<?php

namespace App\Exports;

use App\Enums\SegmentStatus;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Support\Spreadsheet\TableExport;

/** All passengers booked on a flight with travel documents (XLSX / CSV). */
class FlightPassengersExport extends TableExport
{
    public function __construct(private readonly Flight $flight) {}

    protected function rows(): iterable
    {
        return PassengerSegment::query()->with(['passenger.booking.agency', 'ssrCodes'])
            ->where('flight_id', $this->flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled])
            ->get()
            ->sortBy(fn ($s) => $s->passenger->last_name.$s->passenger->first_name)
            ->map(fn (PassengerSegment $segment) => $this->row($segment));
    }

    protected function headings(): array
    {
        return ['First name', 'Middle name', 'Last name', 'Type', 'Nationality', 'Document type', 'Document number',
            'Issuing country', 'Date of birth', 'Issue date', 'Expiry date', 'Gender', 'PNR', 'Ticket', 'Cabin', 'Seat',
            'Status', 'Agency', 'Email', 'Phone', 'SSR'];
    }

    /** @return array<int, mixed> */
    private function row(PassengerSegment $segment): array
    {
        $p = $segment->passenger;

        return [
            $p->first_name, $p->middle_name, $p->last_name, $p->type?->value, $p->nationality, $p->document_type,
            $p->document_number, $p->document_issuing_country, $p->date_of_birth?->toDateString(),
            $p->document_issued_at?->toDateString(), $p->document_expires_at?->toDateString(), $p->gender?->value,
            $p->booking->pnr, $segment->ticket_number, $segment->cabin_class->value, $segment->seat_number,
            $segment->status->value, $p->booking->agency?->name, $p->email, $p->phone,
            $segment->ssrCodes->pluck('code')->implode(' '),
        ];
    }
}
