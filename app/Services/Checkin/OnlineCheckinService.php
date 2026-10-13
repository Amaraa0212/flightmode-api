<?php

namespace App\Services\Checkin;

use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Mail\VerificationCodeMail;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use App\Services\Seat\SeatHoldService;
use App\Services\Seat\SeatMapService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Public online check-in (no login):
 *   1. lookup by person code        → lookup token
 *   2. e-mail a verification code   → code
 *   3. verify the code              → session token (X-Checkin-Token)
 *   4. seat map / hold seat / check in / boarding passes with the session token
 */
class OnlineCheckinService
{
    public function __construct(
        private readonly SeatMapService $seatMap,
        private readonly SeatHoldService $holds,
        private readonly CheckinService $checkin,
        private readonly BoardingPassService $boardingPasses,
    ) {}

    /** @return array{lookup_token: string, flights: array<int, array<string, mixed>>} */
    public function lookup(string $personCode): array
    {
        $passengers = Passenger::query()->where('person_code', mb_strtoupper(trim($personCode)))
            ->whereNull('infant_of_passenger_id')->latest('id')->limit(10)->get();

        $segments = PassengerSegment::query()->with(['flight.origin', 'flight.destination', 'passenger'])
            ->whereIn('passenger_id', $passengers->pluck('id'))
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->get()
            ->filter(fn (PassengerSegment $s) => $this->isOpenOnline($s->flight));

        if ($segments->isEmpty()) {
            throw BusinessRuleException::make('no_online_checkin', 'No flight is open for online check-in for this number.', status: 404);
        }

        $token = Str::random(40);
        Cache::put($this->lookupKey($token), [
            'booking_ids' => $segments->pluck('passenger.booking_id')->unique()->values()->all(),
        ], now()->addMinutes(30));

        return [
            'lookup_token' => $token,
            'flights' => $segments->map(fn (PassengerSegment $s) => [
                'flight_id' => $s->flight_id,
                'flight_number' => $s->flight->flight_number,
                'origin' => $s->flight->origin->iata_code,
                'destination' => $s->flight->destination->iata_code,
                'departure_at' => $s->flight->departureAt()->toIso8601String(),
                'passenger' => $s->passenger->last_name.'/'.$s->passenger->first_name,
                'is_checked_in' => $s->isCheckedIn(),
            ])->values()->all(),
        ];
    }

    public function sendCode(string $lookupToken, string $email): void
    {
        $lookup = $this->lookupOrFail($lookupToken);
        $code = (string) random_int(1000, 9999);

        Cache::put($this->codeKey($lookupToken), $code, now()->addMinutes((int) config('flightmode.online_checkin.code_ttl_minutes')));
        Mail::to($email)->send(new VerificationCodeMail($code));

        Cache::put($this->lookupKey($lookupToken), $lookup + ['email' => $email], now()->addMinutes(30));
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

        $session = Str::random(48);
        $ttl = (int) config('flightmode.online_checkin.session_ttl_minutes');
        Cache::put($this->sessionKey($session), $lookup, now()->addMinutes($ttl));

        return ['session_token' => $session, 'expires_in' => $ttl * 60];
    }

    /** @return array<int> booking ids the session may act on */
    public function sessionBookings(string $sessionToken): array
    {
        $session = Cache::get($this->sessionKey($sessionToken));
        if (! $session) {
            throw BusinessRuleException::make('session_expired', 'Your check-in session has expired, please start again.', status: 401);
        }

        return $session['booking_ids'];
    }

    /** @return Collection<int, PassengerSegment> segments of the session on a flight (adults only) */
    public function sessionSegments(string $sessionToken, Flight $flight): Collection
    {
        $segments = PassengerSegment::query()->with(['passenger.infant', 'passenger.booking'])
            ->where('flight_id', $flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereHas('passenger', fn ($q) => $q->whereIn('booking_id', $this->sessionBookings($sessionToken))->whereNull('infant_of_passenger_id'))
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
                }

                // no seat chosen: the automatic pick follows the same restrictions as the online seat map
                $choices = $seat || $segment->seat_number ? null : $this->selectableSeats($flight, $sessionToken);

                $done->push($this->checkin->checkIn($segment, $seat, CheckinChannel::Online,
                    allowCabinChange: false, autoSeatChoices: $choices));
                if ($seat) {
                    $this->holds->release($flight->id, $seat, $sessionToken);
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
