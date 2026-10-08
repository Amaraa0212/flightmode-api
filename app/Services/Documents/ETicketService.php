<?php

namespace App\Services\Documents;

use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Mail\ETicketMail;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;

/** E-ticket (itinerary receipt) of an adult passenger; an infant travelling with them is included. */
class ETicketService
{
    /** @return array<string, mixed> */
    public function data(Passenger $passenger): array
    {
        if ($passenger->isInfant()) {
            throw BusinessRuleException::make('eticket_infant', 'The e-ticket is issued for the adult; the infant is included in it.');
        }

        $passenger->loadMissing(['booking.airline', 'booking.agency', 'infant.segments', 'segments.flight.origin',
            'segments.flight.destination', 'segments.flight.aircraft', 'segments.ssrCodes', 'segments.prepaidBaggage']);

        $segments = $passenger->segments->where('status', '!=', SegmentStatus::Cancelled)->values();
        if ($segments->isEmpty()) {
            throw BusinessRuleException::make('no_segments', 'This passenger has no active flights.');
        }

        $airline = $passenger->booking->airline;
        $freeBaggage = (int) config('flightmode.documents.default_free_baggage_kg');
        $cabinBaggage = (int) config('flightmode.documents.eticket_cabin_baggage_kg');

        $legs = $segments->map(function (PassengerSegment $s) use ($freeBaggage, $cabinBaggage) {
            $f = $s->flight;
            $dep = $f->scheduled_departure_at->copy()->timezone($f->origin->timezone);
            $arr = $f->scheduled_arrival_at->copy()->timezone($f->destination->timezone);

            return [
                'flight_number' => $f->flight_number,
                'from' => ['code' => $f->origin->iata_code, 'city' => $f->origin->city, 'airport' => $f->origin->name],
                'to' => ['code' => $f->destination->iata_code, 'city' => $f->destination->city, 'airport' => $f->destination->name],
                'departure' => $dep->format('Y-m-d H:i'),
                'arrival' => $arr->format('Y-m-d H:i'),
                'terminal' => $f->terminal,
                'cabin' => $s->cabin_class->label(),
                'fare_class' => $s->fare_class,
                'status' => 'OK (Confirmed)',
                'baggage_kg' => (int) ($f->excess_baggage_max_kg ?? $freeBaggage),
                'cabin_baggage_kg' => $cabinBaggage,
                'prepaid_baggage_pieces' => (int) $s->prepaidBaggage->sum('pieces'),
                'duration' => $f->scheduled_departure_at->diff($f->scheduled_arrival_at)->format('%hH %iM'),
                'aircraft' => $f->aircraft?->type,
                'ssr' => $s->ssrCodes->pluck('code')->all(),
            ];
        });

        $infant = $passenger->infant;
        $fare = (float) $segments->sum('fare_amount');
        $tax = (float) $segments->sum('tax_amount');

        return [
            'airline' => [
                'name' => $airline->name,
                'logo_path' => $airline->logo_path ? public_path($airline->logo_path) : null,
                'brand_color' => $airline->brand_color,
                'conditions_of_carriage' => $airline->conditions_of_carriage,
            ],
            'agency' => $passenger->booking->agency?->name,
            'pnr' => $passenger->booking->pnr,
            'issued_at' => $passenger->created_at->format('Y-m-d H:i'),
            'passenger' => [
                'name' => $passenger->last_name.'/'.$passenger->first_name,
                'type' => ($passenger->type ?? PassengerType::Adult)->value,
                'ticket_number' => $segments->first()->ticket_number,
            ],
            'infant' => $infant ? [
                'name' => $infant->last_name.'/'.$infant->first_name,
                'ticket_number' => $infant->segments->first()?->ticket_number,
            ] : null,
            'legs' => $legs->all(),
            'payment' => [
                'currency' => $segments->first()->currency,
                'fare' => number_format($fare, 2, '.', ''),
                'tax' => number_format($tax, 2, '.', ''),
                'total' => number_format($fare + $tax, 2, '.', ''),
            ],
        ];
    }

    /** @return array{filename: string, content: string} */
    public function pdf(Passenger $passenger): array
    {
        $data = $this->data($passenger);

        $pdf = Pdf::loadView('documents.eticket', $data)
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', false);

        return [
            'filename' => 'eticket-'.$data['pnr'].'-'.$passenger->id.'.pdf',
            'content' => $pdf->output(),
        ];
    }

    public function email(Passenger $passenger, ?string $to = null): string
    {
        $to ??= $passenger->email ?? $passenger->booking->contact_email;
        if (! $to) {
            throw BusinessRuleException::make('no_email', 'The passenger has no e-mail address.');
        }

        $file = $this->pdf($passenger);
        Mail::to($to)->send(new ETicketMail($passenger->booking->pnr, $file['filename'], $file['content']));
        $passenger->booking->forceFill(['ticket_emailed_at' => now()])->save();

        return $to;
    }
}
