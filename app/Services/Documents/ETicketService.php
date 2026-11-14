<?php

namespace App\Services\Documents;

use App\Enums\BookingStatus;
use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Mail\ETicketMail;
use App\Models\Booking;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ZipArchive;

/**
 * E-ticket (itinerary receipt) of an adult passenger; an infant travelling with them is included.
 * Printed in English or Mongolian, with or without the price breakdown, as the caller asks.
 */
class ETicketService
{
    public const LANGUAGES = ['en', 'mn'];

    /** Whose details head the e-ticket: the selling agency's or the airline's */
    public const HEADERS = ['agency', 'airline'];

    /**
     * ?lang=en|mn&fare=1|0&issued=1|0&header=agency|airline of a download or e-mail request (prices and the issue
     * date are shown unless 0; the header is the agency's when the booking has one, unless asked otherwise).
     *
     * @return array{lang: string, with_fare: bool, with_issued: bool, header: ?string}
     */
    public static function options(Request $request): array
    {
        $data = $request->validate([
            'lang' => ['nullable', Rule::in(self::LANGUAGES)],
            'fare' => ['nullable', 'boolean'],
            'issued' => ['nullable', 'boolean'],
            'header' => ['nullable', Rule::in(self::HEADERS)],
        ]);

        return [
            'lang' => $data['lang'] ?? 'en',
            'with_fare' => $request->has('fare') ? $request->boolean('fare') : true,
            'with_issued' => $request->has('issued') ? $request->boolean('issued') : true,
            'header' => $data['header'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function data(Passenger $passenger, string $lang = 'en', bool $withFare = true, bool $withIssued = true, ?string $header = null): array
    {
        if ($passenger->isInfant()) {
            throw BusinessRuleException::make('eticket_infant', 'The e-ticket is issued for the adult; the infant is included in it.');
        }

        $passenger->loadMissing(['booking.airline', 'booking.agency', 'infant.segments', 'segments.flight.origin',
            'segments.flight.destination', 'segments.flight.aircraft', 'segments.ssrCodes', 'segments.prepaidBaggage',
            'segments.fare.fareClass']);

        $segments = $passenger->segments->where('status', '!=', SegmentStatus::Cancelled)->sortBy('segment_no')->values();
        if ($segments->isEmpty()) {
            throw BusinessRuleException::make('no_segments', 'This passenger has no active flights.');
        }

        $t = fn (string $key, array $replace = []) => __('eticket.'.$key, $replace, $lang);
        $booking = $passenger->booking;
        $airline = $booking->airline;
        $infant = $passenger->infant;
        $infantSegments = $infant ? $infant->segments->where('status', '!=', SegmentStatus::Cancelled)->keyBy('flight_id') : collect();
        $freeBaggage = (int) config('flightmode.documents.default_free_baggage_kg');
        $cabinBaggage = (int) config('flightmode.documents.eticket_cabin_baggage_kg');
        $checkinHours = (int) config('flightmode.documents.eticket_checkin_opens_hours');
        // one flight, or there and back: outbound / return; otherwise Flight 1, 2, ...
        $round = $segments->count() === 1
            || ($segments->count() === 2 && $segments[0]->flight->origin_airport_id === $segments[1]->flight->destination_airport_id);

        $legs = $segments->values()->map(function (PassengerSegment $s, int $i) use ($t, $lang, $round, $airline, $infantSegments, $freeBaggage, $checkinHours) {
            $f = $s->flight;
            $dep = $f->scheduled_departure_at->copy()->timezone($f->origin->timezone);
            $arr = $f->scheduled_arrival_at->copy()->timezone($f->destination->timezone);
            $minutes = (int) $f->scheduled_departure_at->diffInMinutes($f->scheduled_arrival_at);
            $place = fn ($airport, $terminal) => $airport->city.' ('.$airport->iata_code.')'.($terminal ? ' · '.$t('terminal', ['t' => $terminal]) : '');

            return [
                'title' => $round ? $t($i === 0 ? 'outbound' : 'return') : $t('flight', ['n' => $i + 1]),
                'short' => $round ? $t($i === 0 ? 'outbound_short' : 'return_short') : $t('flight_short', ['n' => $i + 1]),
                'flight_number' => $f->flight_number,
                'date_long' => $this->longDate($dep, $lang),
                'date_short' => $this->shortDate($dep, $lang),
                'from' => ['code' => $f->origin->iata_code, 'place' => $place($f->origin, $f->terminal)],
                'to' => ['code' => $f->destination->iata_code, 'place' => $place($f->destination, null)],
                'departure_time' => $dep->format('H:i'),
                'arrival_time' => $arr->format('H:i'),
                'next_day' => ($days = (int) $dep->copy()->startOfDay()->diffInDays($arr->copy()->startOfDay(), false)) > 0 ? "+{$days}" : '',
                'duration' => sprintf('%02dh %02dm', intdiv($minutes, 60), $minutes % 60),
                'cabin' => $s->cabin_class->label().($s->fare_class ? ' ('.$s->fare_class.')' : ''),
                'operated' => $t('operated', ['airline' => $airline->name]),
                'seats' => implode(' · ', array_filter([$s->seat_number ?? '—', $infantSegments->has($f->id) ? 'INF' : null])),
                'aircraft' => $f->aircraft?->type,
                'baggage_kg' => (int) ($f->excess_baggage_max_kg ?? $freeBaggage),
                'prepaid_baggage_pieces' => (int) $s->prepaidBaggage->sum('pieces'),
                'checkin' => $this->shortDate($dep->copy()->subHours($checkinHours), $lang).' · '.$dep->copy()->subHours($checkinHours)->format('H:i'),
                'gate' => $f->gate,
                'ssr' => $s->ssrCodes->pluck('code')->all(),
            ];
        });

        $money = fn (float $amount, string $currency) => $currency.' '.number_format($amount, 2);
        $currency = $segments->first()->currency;
        $priced = function (Passenger $person, Collection $coupons, string $type) use ($money, $currency) {
            $fare = (float) $coupons->sum('fare_amount');
            $tax = (float) $coupons->sum('tax_amount');

            return [
                'name' => $person->last_name.'/'.$person->first_name,
                'type' => $type,
                'fare_basis' => $coupons->map(fn (PassengerSegment $c) => $c->fare?->fareClass?->booking_code ?? $c->fare_class)->filter()->unique()->implode(' / ') ?: '—',
                'fare' => $fare,
                'tax' => $tax,
                'fare_text' => $money($fare, $currency),
                'tax_text' => $money($tax, $currency),
                'total_text' => $money($fare + $tax, $currency),
            ];
        };

        $type = ($passenger->type ?? PassengerType::Adult) === PassengerType::Child ? 'child' : 'adult';
        $travellers = array_values(array_filter([
            $priced($passenger, $segments, $t($type)),
            $infant ? $priced($infant, $infantSegments->values(), $t('infant')) : null,
        ]));
        $fareTotal = array_sum(array_column($travellers, 'fare'));
        $taxTotal = array_sum(array_column($travellers, 'tax'));
        $tickets = $segments->pluck('ticket_number')->merge($infantSegments->pluck('ticket_number'))->filter()->unique()->values();
        $issued = $passenger->created_at->copy()->timezone(config('flightmode.timezone'));

        return [
            'lang' => $lang,
            'with_fare' => $withFare,
            'with_issued' => $withIssued,
            't' => $t,
            'airline' => [
                'name' => $airline->name,
                'address' => $airline->address,
                'phone' => $airline->phone,
                'email' => $airline->email,
                'logo_path' => $airline->logo_path ? public_path($airline->logo_path) : null,
                'brand_color' => $airline->brand_color ?: '#273aa7',
                'conditions_of_carriage' => $airline->conditions_of_carriage,
            ],
            'agency' => $booking->agency?->name,
            'issuer' => $this->issuer($booking, $header),
            'conditions_html' => $this->conditions($booking, $header),
            'pnr' => $booking->pnr,
            'ticket_numbers' => $tickets->implode(' · ') ?: '—',
            'status' => match (true) {
                $booking->status === BookingStatus::OnHold || $tickets->isEmpty() => $t('on_hold'),
                $booking->status === BookingStatus::Waitlisted => $t('waitlisted'),
                default => $t('ticketed'),
            },
            'paid' => $tickets->isNotEmpty() && $booking->status !== BookingStatus::OnHold,
            'issued_at' => $this->longDate($issued, $lang, false).' ('.$issued->format('H:i').')',
            'travellers' => $travellers,
            'legs' => $legs->all(),
            'checkin_hours' => $checkinHours,
            'cabin_baggage_kg' => $cabinBaggage,
            'payment' => [
                'currency' => $currency,
                'fare' => $money($fareTotal, $currency),
                'tax' => $money($taxTotal, $currency),
                'total' => $money($fareTotal + $taxTotal, $currency),
            ],
        ];
    }

    /** "Friday, 20 November 2026" / "2026 оны 11 сарын 20, Баасан" */
    private function longDate(CarbonInterface $date, string $lang, bool $weekday = true): string
    {
        $month = __('eticket.months', [], $lang)[$date->month - 1];
        $day = __('eticket.weekdays', [], $lang)[$date->dayOfWeekIso - 1];

        return $lang === 'mn'
            ? "{$date->year} оны {$month} сарын {$date->format('d')}".($weekday ? ", {$day}" : '')
            : ($weekday ? "{$day}, " : '')."{$date->format('d')} {$month} {$date->year}";
    }

    /** "20 NOV 2026" / "2026.11.20" */
    private function shortDate(CarbonInterface $date, string $lang): string
    {
        return $lang === 'mn' ? $date->format('Y.m.d') : strtoupper($date->format('d M Y'));
    }

    /**
     * The details at the top of the e-ticket: the agency that sold it (by default when there is one) or the airline.
     *
     * @return array{name: string, address: ?string, phone: ?string, email: ?string, logo_path: ?string}
     */
    /**
     * Conditions of carriage under the e-ticket, as HTML from their Markdown: the agency's own when the ticket carries
     * the agency's details and it has written some, otherwise the airline's (null: the default text).
     */
    private function conditions(Booking $booking, ?string $header): ?string
    {
        $agency = $booking->agency;
        $markdown = $agency && $header !== 'airline' && filled($agency->conditions_of_carriage)
            ? $agency->conditions_of_carriage
            : $booking->airline->conditions_of_carriage;

        return filled($markdown)
            ? strip_tags(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                '<p><br><b><strong><em><i><ul><ol><li><h1><h2><h3><h4>')
            : null;
    }

    private function issuer(Booking $booking, ?string $header): array
    {
        $agency = $booking->agency;
        if ($agency && $header !== 'airline') {
            return ['name' => $agency->name, 'address' => $agency->address, 'phone' => $agency->phone, 'email' => $agency->email, 'logo_path' => null];
        }
        $airline = $booking->airline;

        return [
            'name' => $airline->name,
            'address' => $airline->address,
            'phone' => $airline->phone,
            'email' => $airline->email,
            'logo_path' => $airline->logo_path ? public_path($airline->logo_path) : null,
        ];
    }

    /** @return array{filename: string, content: string} */
    public function pdf(Passenger $passenger, string $lang = 'en', bool $withFare = true, bool $withIssued = true, ?string $header = null): array
    {
        $data = $this->data($passenger, $lang, $withFare, $withIssued, $header);

        $pdf = Pdf::loadView('documents.eticket', $data)
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', false);

        return [
            'filename' => 'eticket-'.$data['pnr'].'-'.$passenger->id.'-'.$lang.'.pdf',
            'content' => $pdf->output(),
        ];
    }

    public function email(Passenger $passenger, ?string $to = null, string $lang = 'en', bool $withFare = true, bool $withIssued = true, ?string $header = null): string
    {
        $to ??= $passenger->email ?? $passenger->booking->contact_email;
        if (! $to) {
            throw BusinessRuleException::make('no_email', 'The passenger has no e-mail address.');
        }

        $file = $this->pdf($passenger, $lang, $withFare, $withIssued, $header);
        Mail::to($to)->send(new ETicketMail($passenger->booking->pnr, $file['filename'], $file['content']));
        $passenger->booking->forceFill(['ticket_emailed_at' => now()])->save();

        return $to;
    }

    /**
     * The e-tickets of several passengers in one ZIP (an infant's ticket is printed with its adult, so it is skipped).
     *
     * @param  iterable<Passenger>  $passengers
     * @return array{filename: string, content: string}
     */
    public function zip(iterable $passengers, string $lang = 'en', bool $withFare = true, bool $withIssued = true, ?string $header = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'etk');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $names = [];
        foreach ($passengers as $passenger) {
            if ($passenger->isInfant()) {
                continue;
            }
            $file = $this->pdf($passenger, $lang, $withFare, $withIssued, $header);
            // PNR-LAST-FIRST.pdf (a name twice in the list gets -2, -3)
            $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $passenger->booking->pnr.' '.$passenger->last_name.' '.$passenger->first_name), '-');
            $names[$name] = ($names[$name] ?? 0) + 1;
            $zip->addFromString($name.($names[$name] > 1 ? '-'.$names[$name] : '').'.pdf', $file['content']);
        }
        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return ['filename' => 'etickets-'.now()->format('Ymd-His').'.zip', 'content' => $content];
    }
}
