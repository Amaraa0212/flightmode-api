<?php

namespace App\Services\Checkin;

use App\Enums\PassengerType;
use App\Exceptions\BusinessRuleException;
use App\Models\AircraftSeat;
use App\Models\PassengerSegment;
use App\Support\PassengerAge;
use Illuminate\Support\Carbon;

/**
 * Boarding pass content and its IATA BCBP (bar coded boarding pass) string.
 * The frontend renders the QR code from `barcode` and prints the pass.
 */
class BoardingPassService
{
    /** @return array<string, mixed> */
    public function build(PassengerSegment $segment, bool $markPrinted = true): array
    {
        $segment->loadMissing(['passenger.infant', 'passenger.booking', 'flight.origin', 'flight.destination', 'flight.airline', 'checkedBaggage.tags']);

        if (! $segment->isCheckedIn() || ! $segment->seat_number) {
            throw BusinessRuleException::make('not_checked_in', 'The passenger must be checked in with a seat to print a boarding pass.');
        }

        $flight = $segment->flight;
        $passenger = $segment->passenger;
        $departure = $flight->departureAt()->copy()->timezone($flight->origin->timezone);
        $arrival = $flight->scheduled_arrival_at->copy()->timezone($flight->destination->timezone);
        $boarding = $departure->copy()->subMinutes((int) config('flightmode.checkin.boarding_minutes_before_departure'));
        $seat = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->where('seat_number', $segment->seat_number)->first();

        if ($markPrinted) {
            $segment->forceFill(['boarding_pass_printed_at' => now()])->save();
        }

        $infant = $passenger->infant;

        return [
            'segment_id' => $segment->id,
            'airline' => [
                'name' => $flight->airline->name,
                'iata_code' => $flight->airline->iata_code,
                'logo_path' => $flight->airline->logo_path,
                'logo_url' => $flight->airline->logoUrl(),
            ],
            'passenger' => [
                'name' => $passenger->last_name.', '.$passenger->first_name,
                'type' => PassengerAge::typeOn($passenger->date_of_birth, $departure)->value,
            ],
            'pnr' => $passenger->booking->pnr,
            'flight_number' => $flight->flight_number,
            'origin' => ['code' => $flight->origin->iata_code, 'name' => $flight->origin->name, 'city' => $flight->origin->city],
            'destination' => ['code' => $flight->destination->iata_code, 'name' => $flight->destination->name, 'city' => $flight->destination->city],
            'departure_date' => $departure->format('Y-m-d'),
            'departure_time' => $departure->format('H:i'),
            'arrival_date' => $arrival->format('Y-m-d'),
            'arrival_time' => $arrival->format('H:i'),
            'boarding_time' => $boarding->format('H:i'),
            'gate' => $flight->gate,
            'terminal' => $flight->terminal,
            'seat_number' => $segment->seat_number,
            'seat_type' => $seat?->seat_type->value,
            'zone' => $seat?->zone,
            'cabin_class' => $segment->cabin_class->value,
            'boarding_sequence' => $segment->boarding_sequence,
            'infant' => $infant ? ['name' => $infant->last_name.', '.$infant->first_name, 'type' => PassengerType::Infant->value] : null,
            'baggage' => [
                'pieces' => (int) $segment->checkedBaggage->sum('pieces'),
                'weight_kg' => (float) $segment->checkedBaggage->sum('weight_kg'),
            ],
            // legacy pass lists every bag: "BAG #01: 0123456789"
            'bag_tags' => $segment->checkedBaggage->flatMap->tags->sortBy('id')->pluck('tag_number')->values()->all(),
            'barcode' => $this->bcbp($segment, $departure),
        ];
    }

    /**
     * IATA BCBP (format M, 1 leg) followed by "<seat> <segment id>".
     * The gate scanner reads the last token as the segment id and the one before it as the seat.
     */
    public function bcbp(PassengerSegment $segment, Carbon $departureLocal): string
    {
        $flight = $segment->flight;
        $passenger = $segment->passenger;

        preg_match('/^([A-Z0-9]{2}[A-Z]?)(\d+)([A-Z]?)$/', strtoupper(str_replace(' ', '', $flight->flight_number)), $m);
        $carrier = $m[1] ?? ($flight->airline->iata_code ?? '');
        $number = ($m[2] ?? '').($m[3] ?? '');

        $seat = (string) $segment->seat_number;

        return 'M1'
            .$this->fixed($passenger->last_name.'/'.$passenger->first_name, 20)
            .'E'
            .$this->fixed($passenger->booking->pnr, 7)
            .$flight->origin->iata_code
            .$flight->destination->iata_code
            .$this->fixed($carrier, 3)
            .$this->fixed($number, 5)
            .str_pad((string) $departureLocal->dayOfYear, 3, '0', STR_PAD_LEFT)
            .$segment->cabin_class->value
            .str_pad($seat, 4, '0', STR_PAD_LEFT)
            .str_pad((string) $segment->boarding_sequence, 4, '0', STR_PAD_LEFT)
            .' 10K'
            .' '.$seat
            .' '.$segment->id;
    }

    /** Parse a scanned barcode back to [segment id, seat]. */
    public function parseScan(string $barcode): array
    {
        $tokens = preg_split('/\s+/', trim($barcode)) ?: [];
        $segmentId = (int) end($tokens);
        $seat = count($tokens) >= 2 ? $tokens[count($tokens) - 2] : null;

        return [$segmentId, $seat];
    }

    private function fixed(string $value, int $length): string
    {
        return str_pad(mb_substr(strtoupper($value), 0, $length), $length);
    }
}
