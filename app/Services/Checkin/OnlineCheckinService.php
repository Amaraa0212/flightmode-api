<?php

namespace App\Services\Checkin;

use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Mail\VerificationCodeMail;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use App\Services\Seat\SeatHoldService;
use App\Services\Seat\SeatMapService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Public online check-in (no login). Two ways in, both ending in a session token (X-Checkin-Token)
 * that is bound to a fixed set of passengers:
 *   - Oyu Tolgoi (SAP):  lookupBySap(person code)               → session for that person only, no further check
 *   - everyone else:     lookupByPnr(PNR + last name) → e-mail code to the booking's stored address
 *                        → verify(code)                         → session for the booking's passengers
 * Then: seat map / hold seat / check in / boarding passes with the session token.
 */
class OnlineCheckinService
{
    public function __construct(
        private readonly SeatMapService $seatMap,
        private readonly SeatHoldService $holds,
        private readonly CheckinService $checkin,
        private readonly BoardingPassService $boardingPasses,
    ) {}

    /** @return array{session_token: string, expires_in: int, flights: array<int, array<string, mixed>>} */
    public function lookupBySap(string $personCode): array
    {
        $passengerIds = Passenger::query()->where('person_code', mb_strtoupper(trim($personCode)))
            ->whereNull('infant_of_passenger_id')->latest('id')->limit(10)->pluck('id')->all();

        $segments = $this->openSegments($passengerIds);

        return $this->startSession($segments->pluck('passenger_id')->unique()->values()->all()) + ['flights' => $this->flights($segments)];
    }

    /** @return array{lookup_token: string, email_hints: array<int, string>, flights: array<int, array<string, mixed>>} */
    public function lookupByPnr(string $pnr, string $lastName): array
    {
        $booking = Booking::query()->where('pnr', mb_strtoupper(trim($pnr)))->first();
        $lastName = mb_strtoupper(trim($lastName));

        $passengers = $booking?->passengers()->whereNull('infant_of_passenger_id')->get(['id', 'last_name']) ?? collect();

        if (! $passengers->contains(fn (Passenger $p) => mb_strtoupper(trim($p->last_name)) === $lastName)) {
            throw BusinessRuleException::make('booking_not_found', 'No booking matches this PNR and last name.', status: 404);
        }

        $segments = $this->openSegments($passengers->pluck('id')->all());

        $emails = $this->bookingEmails($booking);
        if ($emails === []) {
            throw BusinessRuleException::make('no_contact_email', 'This booking has no e-mail address for the verification code. Please check in at the airport counter.');
        }

        $token = Str::random(40);
        Cache::put($this->lookupKey($token), [
            'passenger_ids' => $segments->pluck('passenger_id')->unique()->values()->all(),
            'emails' => $emails,
        ], now()->addMinutes(30));

        $this->sendCode($token);

        return [
            'lookup_token' => $token,
            'email_hints' => array_map($this->maskEmail(...), $emails),
            'flights' => $this->flights($segments),
        ];
    }

    /** (Re)sends the code to the booking's stored addresses — never to an address the visitor types. */
    public function sendCode(string $lookupToken): void
    {
        $lookup = $this->lookupOrFail($lookupToken);
        $code = (string) random_int(1000, 9999);

        Cache::put($this->codeKey($lookupToken), $code, now()->addMinutes((int) config('flightmode.online_checkin.code_ttl_minutes')));
        Mail::to($lookup['emails'])->send(new VerificationCodeMail($code));
    }

    /** @return array{session_token: string, expires_in: int} */
    public function verify(string $lookupToken, string $code): array
    {
        $lookup = $this->lookupOrFail($lookupToken);

        if (! hash_equals((string) Cache::get($this->codeKey($lookupToken)), $code)) {
            throw BusinessRuleException::make('invalid_code', 'The verification code is wrong or expired.');
        }

        Cache::forget($this->codeKey($lookupToken));
        Cache::forget($this->lookupKey($lookupToken));

        return $this->startSession($lookup['passenger_ids']);
    }

    /** @return array<int> passenger ids the session may act on */
    public function sessionPassengers(string $sessionToken): array
    {
        $session = Cache::get($this->sessionKey($sessionToken));
        if (! $session) {
            throw BusinessRuleException::make('session_expired', 'Your check-in session has expired, please start again.', status: 401);
        }

        return $session['passenger_ids'];
    }

    /** @return array<int, array<string, mixed>> flights of the session that are open online, with its passengers on each */
    public function sessionFlights(string $sessionToken): array
    {
        return $this->flights($this->openSegments($this->sessionPassengers($sessionToken)));
    }

    /**
     * @param  array<int>  $passengerIds
     * @return Collection<int, PassengerSegment>
     */
    private function openSegments(array $passengerIds): Collection
    {
        $segments = PassengerSegment::query()->with(['flight.origin', 'flight.destination', 'flight.aircraft', 'passenger'])
            ->whereIn('passenger_id', $passengerIds)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->get();
        $open = $segments->filter(fn (PassengerSegment $s) => $this->isOpenOnline($s->flight));

        if ($open->isEmpty()) {
            // a flight that will open later: say when, instead of "nothing found"
            $next = $segments->map->flight->unique('id')
                ->map(fn (Flight $f) => ['flight' => $f, 'opens_at' => $this->opensAt($f)])
                ->filter(fn ($x) => $x['opens_at']?->isFuture())
                ->sortBy(fn ($x) => $x['opens_at'])->first();
            if ($next) {
                throw BusinessRuleException::make('online_checkin_not_open_yet',
                    "Online check-in for {$next['flight']->flight_number} opens at {$next['opens_at']->toIso8601String()}.",
                    [
                        'flight_number' => $next['flight']->flight_number,
                        'opens_at' => $next['opens_at']->toIso8601String(),
                        // the time the passenger reads: the departure airport's clock
                        'opens_at_local' => $next['opens_at']->copy()->timezone($next['flight']->origin->timezone)->format('Y-m-d H:i'),
                    ], status: 404);
            }

            throw BusinessRuleException::make('no_online_checkin', 'No flight is open for online check-in for this booking.', status: 404);
        }

        return $open;
    }

    /**
     * @param  Collection<int, PassengerSegment>  $segments
     * @return array<int, array<string, mixed>>
     */
    private function flights(Collection $segments): array
    {
        return $segments->groupBy('flight_id')->map(function (Collection $group) {
            $flight = $group->first()->flight;

            return [
                'flight_id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'origin' => $flight->origin->iata_code,
                'origin_city' => $flight->origin->city,
                'destination' => $flight->destination->iata_code,
                'destination_city' => $flight->destination->city,
                'departure_at' => $flight->departureAt()->toIso8601String(),
                // what the passenger reads: the departure airport's clock
                'departure_local' => $flight->departureAt()->copy()->timezone($flight->origin->timezone)->format('Y-m-d H:i'),
                'checkin_closes_local' => $flight->departureAt()->copy()->subMinutes((int) $flight->online_checkin_closes_minutes)
                    ->timezone($flight->origin->timezone)->format('Y-m-d H:i'),
                'aircraft_type' => $flight->aircraft?->type,
                'passengers' => $group->map(fn (PassengerSegment $s) => [
                    'name' => $s->passenger->last_name.'/'.$s->passenger->first_name,
                    'is_checked_in' => $s->isCheckedIn(),
                    'seat_number' => $s->isCheckedIn() ? $s->seat_number : null,
                    'cabin_class' => $s->cabin_class->value,
                ])->values()->all(),
            ];
        })->sortBy('departure_at')->values()->all();
    }

    /**
     * @param  array<int>  $passengerIds
     * @return array{session_token: string, expires_in: int}
     */
    private function startSession(array $passengerIds): array
    {
        $session = Str::random(48);
        $ttl = (int) config('flightmode.online_checkin.session_ttl_minutes');
        Cache::put($this->sessionKey($session), ['passenger_ids' => $passengerIds], now()->addMinutes($ttl));

        return ['session_token' => $session, 'expires_in' => $ttl * 60];
    }

    /** @return array<int, string> */
    private function bookingEmails(Booking $booking): array
    {
        return collect([$booking->contact_email])
            ->merge($booking->passengers()->pluck('email'))
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    private function maskEmail(string $email): string
    {
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 1).str_repeat('*', max(2, mb_strlen($user) - 1)).'@'.$domain;
    }

    /** @return Collection<int, PassengerSegment> segments of the session on a flight (adults only) */
    public function sessionSegments(string $sessionToken, Flight $flight): Collection
    {
        $segments = PassengerSegment::query()->with(['passenger.infant', 'passenger.booking'])
            ->where('flight_id', $flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereIn('passenger_id', $this->sessionPassengers($sessionToken))
            ->get();

        if ($segments->isEmpty()) {
            throw BusinessRuleException::make('no_passengers', 'No passenger of this session is on this flight.', status: 404);
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    public function seatMap(string $sessionToken, Flight $flight): array
    {
        $this->sessionSegments($sessionToken, $flight);
        $this->assertOpenOnline($flight);

        return $this->seatMap->forFlight($flight, forOnlineCheckin: true, holder: $sessionToken);
    }

    public function holdSeat(string $sessionToken, Flight $flight, string $seatNumber): void
    {
        $this->sessionSegments($sessionToken, $flight);
        $this->assertSeatSelectable($flight, $seatNumber, $sessionToken);

        if (! $this->holds->hold($flight->id, $seatNumber, $sessionToken)) {
            throw BusinessRuleException::make('seat_held', 'Someone else is choosing this seat right now.');
        }
    }

    public function releaseSeat(string $sessionToken, Flight $flight, string $seatNumber): void
    {
        $this->sessionSegments($sessionToken, $flight);
        $this->holds->release($flight->id, strtoupper($seatNumber), $sessionToken);
    }

    /**
     * @param  array<int, array{segment_id: int, seat_number?: ?string}>  $choices
     * @return Collection<int, PassengerSegment>
     */
    public function checkIn(string $sessionToken, Flight $flight, array $choices): Collection
    {
        $this->assertOpenOnline($flight);
        $allowed = $this->sessionSegments($sessionToken, $flight)->keyBy('id');

        return DB::transaction(function () use ($choices, $allowed, $flight, $sessionToken) {
            $done = collect();
            foreach ($choices as $choice) {
                $segment = $allowed->get($choice['segment_id']);
                if (! $segment) {
                    throw BusinessRuleException::make('segment_not_in_session', 'Passenger does not belong to this check-in session.', status: 403);
                }

                $seat = isset($choice['seat_number']) ? strtoupper($choice['seat_number']) : null;
                if ($seat && $seat !== $segment->seat_number) {
                    $this->assertSeatSelectable($flight, $seat, $sessionToken);
                    // claim it atomically: someone else may have started holding it since the seat map was drawn
                    if (! $this->holds->hold($flight->id, $seat, $sessionToken)) {
                        throw BusinessRuleException::make('seat_held', 'Someone else is choosing this seat right now.');
                    }
                }

                // no seat chosen: the automatic pick follows the online seat map (zones, exits, other people's holds),
                // read under the flight lock so it sees every hold and check-in made before it
                $autoChoices = $seat || $segment->seat_number ? null : fn () => $this->selectableSeats($flight, $sessionToken);

                $done->push($this->checkin->checkIn($segment, $seat, CheckinChannel::Online,
                    allowCabinChange: false, autoSeatChoices: $autoChoices));
                if ($seat) {
                    // check-in announces the seat as occupied
                    $this->holds->release($flight->id, $seat, $sessionToken, announce: false);
                }
            }

            return $done;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function boardingPasses(string $sessionToken, Flight $flight): array
    {
        return $this->sessionSegments($sessionToken, $flight)
            ->filter(fn (PassengerSegment $s) => $s->isCheckedIn())
            ->map(fn (PassengerSegment $s) => $this->boardingPasses->build($s))
            ->values()->all();
    }

    /** When online check-in opens; null when it is switched off, the flight is not bookable online or it opens any time. */
    private function opensAt(Flight $flight): ?Carbon
    {
        if (! $flight->online_checkin_enabled || ! in_array($flight->status, [FlightStatus::Scheduled, FlightStatus::CheckinOpen], true)
            || ! $flight->online_checkin_opens_minutes) {
            return null;
        }

        return $flight->departureAt()->copy()->subMinutes($flight->online_checkin_opens_minutes);
    }

    public function isOpenOnline(Flight $flight): bool
    {
        if (! $flight->online_checkin_enabled || ! in_array($flight->status, [FlightStatus::Scheduled, FlightStatus::CheckinOpen], true)) {
            return false;
        }

        $departure = $flight->departureAt();
        $opens = $flight->online_checkin_opens_minutes ? $departure->copy()->subMinutes($flight->online_checkin_opens_minutes) : null;
        $closes = $departure->copy()->subMinutes((int) ($flight->online_checkin_closes_minutes ?? 0));

        return ($opens === null || now()->gte($opens)) && now()->lt($closes);
    }

    private function assertOpenOnline(Flight $flight): void
    {
        if (! $this->isOpenOnline($flight)) {
            throw BusinessRuleException::make('online_checkin_closed', 'Online check-in is not open for this flight.');
        }
    }

    /** @return array<string> seats that can be chosen online right now */
    private function selectableSeats(Flight $flight, string $holder): array
    {
        return collect($this->seatMap->forFlight($flight, forOnlineCheckin: true, holder: $holder)['seats'])
            ->where('state', SeatMapService::AVAILABLE)
            ->pluck('seat_number')->values()->all();
    }

    private function assertSeatSelectable(Flight $flight, string $seatNumber, string $holder): void
    {
        $seat = collect($this->seatMap->forFlight($flight, forOnlineCheckin: true, holder: $holder)['seats'])
            ->firstWhere('seat_number', strtoupper($seatNumber));

        if (! $seat || $seat['state'] !== SeatMapService::AVAILABLE) {
            throw BusinessRuleException::make('seat_not_available', "Seat {$seatNumber} cannot be chosen online.");
        }
    }

    /** @return array<string, mixed> */
    private function lookupOrFail(string $token): array
    {
        return Cache::get($this->lookupKey($token))
            ?? throw BusinessRuleException::make('lookup_expired', 'Please search for your booking again.', status: 401);
    }

    private function lookupKey(string $token): string
    {
        return "oci:lookup:{$token}";
    }

    private function codeKey(string $token): string
    {
        return "oci:code:{$token}";
    }

    private function sessionKey(string $token): string
    {
        return "oci:session:{$token}";
    }
}
