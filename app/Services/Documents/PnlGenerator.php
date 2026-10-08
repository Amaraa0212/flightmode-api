<?php

namespace App\Services\Documents;

use App\Enums\Gender;
use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use Illuminate\Support\Carbon;

/**
 * SITA PNL (passenger name list) text file of a flight.
 * Same content as the legacy generator (which existed twice), with the routing header from config.
 */
class PnlGenerator
{
    /** @return array{filename: string, content: string} */
    public function generate(Flight $flight): array
    {
        $flight->loadMissing(['origin', 'destination', 'airline']);
        $departure = $flight->scheduled_departure_at->copy()->timezone($flight->origin->timezone);
        $baggageKg = $this->freeBaggageKg($flight);

        $segments = PassengerSegment::query()->with(['passenger.booking', 'passenger.infant'])
            ->where('flight_id', $flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->get()
            ->sortByDesc(fn ($s) => $s->passenger->date_of_birth);

        $infantTickets = PassengerSegment::query()->where('flight_id', $flight->id)
            ->whereIn('passenger_id', $segments->pluck('passenger.infant.id')->filter())
            ->pluck('ticket_number', 'passenger_id');

        $body = '';
        $count = 0;
        foreach ($segments as $segment) {
            $pax = $segment->passenger;
            $last = $this->clean($pax->last_name);
            $first = $this->clean($pax->first_name);
            $isChild = $pax->type === PassengerType::Child;
            $title = $isChild
                ? ($pax->gender === Gender::Female ? 'MISS' : 'MSTR')
                : ($pax->gender === Gender::Female ? 'MRS' : 'MR');
            $count++;

            $body .= "1{$last}/{$first}{$title}\r\n";
            $body .= ".C/CHARTGRP\r\n";
            $body .= ".L/{$pax->booking->pnr}\r\n";
            $body .= ".R/TKNE HK1 {$segment->ticket_number}/1\r\n";

            $infant = $pax->infant;
            if ($infant) {
                $body .= '.R/TKNE HK1 INF'.$infantTickets->get($infant->id)."/1\r\n";
            }

            $body .= ".R/CKIN HK1 BAGGAGE {$baggageKg}KG\r\n";

            if ($isChild) {
                $body .= '.R/CHLD HK1 '.$this->date($pax->date_of_birth)."\r\n";
            }
            if ($infant) {
                $body .= '.R/INFT HK1 '.$this->date($infant->date_of_birth)." {$infant->last_name}/{$infant->first_name}\r\n";
            }

            $body .= '.R/DOCS HK1/P/'.$this->wrap($this->docs($pax, true))."\r\n";
            if ($infant) {
                $body .= '.R/DOCS HK1/P/'.$this->wrap($this->docs($infant, false))."\r\n";
                $body .= ".RN/{$infant->first_name}\r\n";
            }
        }

        $address = config('flightmode.documents.pnl_routing')[$flight->origin->iata_code] ?? '';
        $content = "\x01QP {$address}\r\n"
            .config('flightmode.documents.pnl_sender').' '.$departure->format('dHi')."\r\n"
            ."\x02PNL\r\n"
            .$flight->flight_number.'/'.strtoupper($departure->format('dM')).' '.$flight->origin->iata_code."\r\n"
            .'-'.$flight->destination->iata_code.str_pad((string) $count, 3, '0', STR_PAD_LEFT)."Y\r\n"
            .$body
            ."ENDPNL\r\n";

        return [
            'filename' => 'PNL '.$flight->flight_number.' '.$departure->format('dMy Hi').' '.$flight->route().'.txt',
            'content' => $content,
        ];
    }

    private function freeBaggageKg(Flight $flight): int
    {
        $rule = $flight->baggageRules()->where('ssr_code', BaggageRule::SSR_FREE)->first();

        return (int) ($rule?->weight_kg ?: config('flightmode.documents.default_free_baggage_kg'));
    }

    private function docs(Passenger $pax, bool $withFirstName): string
    {
        return implode('/', [
            $pax->nationality,
            $pax->document_number,
            $pax->document_issuing_country,
            $this->date($pax->date_of_birth),
            $pax->gender?->value,
            $this->date($pax->document_expires_at),
            $this->clean($pax->last_name),
            $withFirstName ? $this->clean($pax->first_name) : '',
        ]);
    }

    /** SITA lines are max 64 chars: continue long DOCS lines with ".RN/". */
    private function wrap(string $line): string
    {
        return strlen($line) > 50 ? substr($line, 0, 50)."\r\n.RN/".substr($line, 50) : $line;
    }

    private function clean(?string $name): string
    {
        return preg_replace('/[^A-Z]/', '', strtoupper((string) $name));
    }

    private function date(?Carbon $date): string
    {
        return $date ? strtoupper($date->format('dMy')) : '';
    }
}
