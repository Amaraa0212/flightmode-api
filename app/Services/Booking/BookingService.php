<?php

namespace App\Services\Booking;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\AgencyAllocationFare;
use App\Models\Booking;
use App\Models\CheckedBaggage;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use App\Models\SsrCode;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Allocation\AllocationService;
use App\Support\PassengerAge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creating and changing bookings. The only place that writes bookings / passengers / segments
 * (agency portal, agency API, Excel import, back office and DCS go-show all end up here).
 */
class BookingService
{
    /** Passenger attributes copied from input as-is (after normalisation). */
    private const PASSENGER_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'nationality', 'person_code',
        'document_type', 'document_number', 'document_issuing_country', 'document_issued_at', 'document_expires_at',
        'email', 'phone', 'emergency_contact', 'remark',
    ];

    public function __construct(
        private readonly AllocationService $allocations,
        private readonly PassengerRules $rules,
        private readonly PnrGenerator $pnr,
        private readonly TicketNumberGenerator $tickets,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param array{allocation_id?: ?int, return_allocation_id?: ?int, flight_ids?: array<int>, contact_email?: ?string,
     *              contact_phone?: ?string, passengers: array<int, array<string, mixed>>} $data
     */
    public function create(array $data, User $actor, BookingSource $source, bool $goShow = false): Booking
    {
        return DB::transaction(function () use ($data, $actor, $source, $goShow) {
            $allocation = isset($data['allocation_id'])
                ? AgencyAllocation::query()->with(['cabins', 'fares.fare'])->lockForUpdate()->findOrFail($data['allocation_id'])
                : null;
            $returnAllocation = isset($data['return_allocation_id'])
                ? AgencyAllocation::query()->with(['cabins', 'fares.fare'])->lockForUpdate()->findOrFail($data['return_allocation_id'])
                : null;

            $flights = $this->resolveFlights($data, $allocation, $returnAllocation);
            $firstFlight = $flights->first();

            if ($allocation) {
                $this->assertAllocationUsable($allocation, $actor);
            }
            if ($returnAllocation) {
                $this->assertAllocationUsable($returnAllocation, $actor);
                if ($returnAllocation->agency_id !== $allocation?->agency_id) {
                    throw BusinessRuleException::make('return_allocation_mismatch', 'The return allocation belongs to another agency.');
                }
            }

            $booking = Booking::create([
                'pnr' => $this->pnr->generate(),
                'airline_id' => $firstFlight->airline_id,
                'agency_id' => $allocation?->agency_id,
                'agency_allocation_id' => $allocation?->id,
                'source' => $source,
                'status' => BookingStatus::Confirmed,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'created_by' => $actor->id,
            ]);

            $waitlisted = false;
            foreach ($data['passengers'] as $row) {
                $waitlisted = $this->addPassenger($booking, $flights, $row, $allocation, $returnAllocation, $goShow) || $waitlisted;
            }

            if ($waitlisted) {
                $booking->update(['status' => BookingStatus::Waitlisted]);
            }

            $this->logger->log($this->channel($source), 'booking.created',
                "PNR {$booking->pnr}, ".count($data['passengers']).' passenger(s)', $booking, $firstFlight);

            return $booking->load(['passengers.segments.flight', 'passengers.infant']);
        });
    }

    /**
     * Change passenger details. Cabin / fare changes are re-checked against the allocation.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePassenger(Passenger $passenger, array $data, User $actor, ActivityChannel $channel): Passenger
    {
        return DB::transaction(function () use ($passenger, $data, $actor, $channel) {
            $passenger = Passenger::query()->with(['booking.allocation.cabins', 'booking.allocation.fares.fare', 'segments.flight.origin', 'segments.flight.destination', 'infant.segments'])
                ->lockForUpdate()->findOrFail($passenger->id);
            $booking = $passenger->booking;
            $allocation = $booking->allocation;
            $segments = $passenger->segments->where('status', '!=', SegmentStatus::Cancelled);
            $firstFlight = $segments->first()?->flight;

            if (! $firstFlight) {
                throw BusinessRuleException::make('passenger_cancelled', 'This passenger has no active flights.');
            }

            if ($allocation && $channel !== ActivityChannel::Dcs) {
                $this->assertAllocationUsable($allocation, $actor);
            }

            $attributes = $this->passengerAttributes(array_merge($passenger->only(self::PASSENGER_FIELDS), $data));
            $infantData = array_key_exists('infant', $data) ? $data['infant'] : ($passenger->infant ? $passenger->infant->only(self::PASSENGER_FIELDS) : null);

            $this->rules->validate($firstFlight, $attributes, $infantData);
            $this->assertNotDuplicate($segments->pluck('flight_id')->all(), $attributes['document_number'] ?? null, $passenger->id);

            $original = $passenger->getOriginal();
            $passenger->fill($attributes + ['type' => PassengerAge::typeOn($attributes['date_of_birth'] ?? null, $firstFlight->departureAt())]);
            $passenger->save();

            if (isset($data['cabin_class']) || array_key_exists('allocation_fare_id', $data) || array_key_exists('fare_id', $data)) {
                $this->changeCabinOrFare($passenger, $segments, $data, $allocation);
            }

            if (array_key_exists('ssr_codes', $data)) {
                foreach ($segments as $segment) {
                    $this->syncSsr($segment, $data['ssr_codes'] ?? [], $booking->airline_id);
                }
            }

            if (array_key_exists('infant', $data)) {
                $this->syncInfant($passenger, $data['infant'], $segments, $allocation);
            }

            $this->logger->logChanges($channel, 'passenger.updated', $passenger, $original,
                "PNR {$booking->pnr}", $firstFlight, $passenger);

            return $passenger->refresh()->load(['segments.flight', 'infant.segments']);
        });
    }

    /** Cancel a passenger (and their infant) on all flights. Checked-in passengers must be un-checked first. */
    public function cancelPassenger(Passenger $passenger, ActivityChannel $channel, ?string $reason = null): void
    {
        DB::transaction(function () use ($passenger, $channel, $reason) {
            $passenger = Passenger::query()->with(['segments', 'infant.segments', 'booking'])->lockForUpdate()->findOrFail($passenger->id);

            if ($passenger->segments->contains(fn (PassengerSegment $s) => $s->isCheckedIn())) {
                throw BusinessRuleException::make('passenger_checked_in', 'Cancel the check-in before cancelling the passenger.');
            }
            $this->assertNoBaggage($passenger->segments, 'cancelling the passenger');

            foreach (array_filter([$passenger, $passenger->infant]) as $person) {
                $person->segments()->update(['status' => SegmentStatus::Cancelled->value, 'seat_number' => null]);
                $person->delete();
            }

            if (! $passenger->booking->passengers()->exists()) {
                $passenger->booking->update(['status' => BookingStatus::Cancelled]);
            }

            $this->logger->log($channel, 'passenger.cancelled', $reason, $passenger, $passenger->segments->first()?->flight_id, $passenger);
        });
    }

    /** @param array<int> $segmentIds */
    public function setWaitlisted(array $segmentIds, bool $waitlisted): int
    {
        return DB::transaction(function () use ($segmentIds, $waitlisted) {
            $segments = PassengerSegment::query()->whereIn('id', $segmentIds)->lockForUpdate()->get();

            foreach ($segments as $segment) {
                if ($segment->isCheckedIn()) {
                    throw BusinessRuleException::make('passenger_checked_in', 'Checked-in passengers cannot be waitlisted.');
                }
            }
            if ($waitlisted) {
                $this->assertNoBaggage($segments, 'waitlisting');
            }

            PassengerSegment::query()->whereIn('id', $segments->pluck('id'))
                ->update(['status' => ($waitlisted ? SegmentStatus::Waitlisted : SegmentStatus::Booked)->value, 'seat_number' => null]);

            $this->logger->log(ActivityChannel::Admin, $waitlisted ? 'passenger.waitlisted' : 'passenger.unwaitlisted',
                'Segments: '.$segments->pluck('id')->implode(', '));

            return $segments->count();
        });
    }

    /**
     * Put passengers into one booking (the booking of the first passenger). Empty bookings are removed.
     *
     * @param  array<int>  $passengerIds
     */
    public function mergeIntoOneBooking(array $passengerIds): Booking
    {
        return DB::transaction(function () use ($passengerIds) {
            $passengers = Passenger::query()->with('booking')->whereIn('id', $passengerIds)->get()->sortBy(fn ($p) => array_search($p->id, $passengerIds));
            $target = $passengers->first()->booking;

            if ($passengers->pluck('booking.airline_id')->unique()->count() > 1) {
                throw BusinessRuleException::make('different_airlines', 'Passengers of different airlines cannot share a booking.');
            }

            foreach ($passengers as $passenger) {
                $source = $passenger->booking;
                if ($source->id === $target->id) {
                    continue;
                }
                Passenger::query()->whereIn('id', array_filter([$passenger->id, $passenger->infant?->id]))->update(['booking_id' => $target->id]);
                if (! $source->passengers()->exists()) {
                    $source->delete();
                }
            }

            $this->logger->log(ActivityChannel::Admin, 'booking.merged', "PNR {$target->pnr}", $target);

            return $target->load('passengers');
        });
    }

    /** Move a passenger (with their infant) to a new booking of their own. */
    public function splitPassenger(Passenger $passenger): Booking
    {
        return DB::transaction(function () use ($passenger) {
            $old = $passenger->booking;
            $new = $old->replicate(['pnr']);
            $new->pnr = $this->pnr->generate();
            $new->save();

            Passenger::query()->whereIn('id', array_filter([$passenger->id, $passenger->infant?->id]))->update(['booking_id' => $new->id]);

            $this->logger->log(ActivityChannel::Admin, 'booking.split', "PNR {$old->pnr} → {$new->pnr}", $new);

            return $new->load('passengers');
        });
    }

    /**
     * Back office: move a passenger from one flight to another of the same airline.
     */
    public function rebook(PassengerSegment $segment, Flight $newFlight): PassengerSegment
    {
        return DB::transaction(function () use ($segment, $newFlight) {
            $segment = PassengerSegment::query()->with(['passenger.infant', 'flight'])->lockForUpdate()->findOrFail($segment->id);

            if ($segment->isCheckedIn()) {
                throw BusinessRuleException::make('passenger_checked_in', 'Cancel the check-in before rebooking.');
            }
            $this->assertNoBaggage(collect([$segment]), 'rebooking');
            if ($newFlight->airline_id !== $segment->flight->airline_id) {
                throw BusinessRuleException::make('flight_other_airline', 'The new flight belongs to another airline.');
            }
            if (PassengerSegment::query()->where('passenger_id', $segment->passenger_id)->where('flight_id', $newFlight->id)->exists()) {
                throw BusinessRuleException::make('already_on_flight', 'The passenger is already on this flight.');
            }

            $oldFlightId = $segment->flight_id;
            foreach (array_filter([$segment->passenger, $segment->passenger->infant]) as $person) {
                PassengerSegment::query()->where('passenger_id', $person->id)->where('flight_id', $oldFlightId)
                    ->update(['flight_id' => $newFlight->id, 'seat_number' => null]);
            }

            $this->logger->log(ActivityChannel::Admin, 'passenger.rebooked', "Flight {$oldFlightId} → {$newFlight->id}",
                $segment, $newFlight, $segment->passenger_id);

            return $segment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Flight>  $flights
     * @return bool whether the passenger was waitlisted
     */
    private function addPassenger(Booking $booking, Collection $flights, array $row, ?AgencyAllocation $allocation, ?AgencyAllocation $returnAllocation, bool $goShow): bool
    {
        $firstFlight = $flights->first();
        $attributes = $this->passengerAttributes($row);
        $infantData = isset($row['infant']) ? $this->passengerAttributes($row['infant']) : null;
        $cabin = CabinClass::from($row['cabin_class'] ?? CabinClass::Economy->value);
        $type = PassengerAge::typeOn($attributes['date_of_birth'] ?? null, $firstFlight->departureAt());

        if ($type === PassengerType::Infant) {
            throw BusinessRuleException::make('infant_without_adult', 'An infant must be added together with an adult.');
        }

        $this->rules->validate($firstFlight, $attributes, $infantData);
        $this->assertNotDuplicate($flights->pluck('id')->all(), $attributes['document_number'] ?? null);

        $bucket = $this->resolveBucket($allocation, $row['allocation_fare_id'] ?? null, $cabin);
        $returnBucket = $this->resolveBucket($returnAllocation, $row['return_allocation_fare_id'] ?? null, $cabin);

        $waitlisted = false;
        foreach (array_filter([$allocation, $returnAllocation]) as $alloc) {
            try {
                $this->allocations->assertCanTake($alloc, $cabin, $alloc === $allocation ? $bucket : $returnBucket);
            } catch (BusinessRuleException $e) {
                if (! $alloc->is_waitlist) {
                    throw $e;
                }
                $waitlisted = true;
            }
        }

        if ($infantData) {
            foreach ($flights as $flight) {
                $this->assertInfantCapacity($flight);
            }
        }

        $passenger = $booking->passengers()->create($attributes + ['type' => $type]);
        $directFare = isset($row['fare_id']) ? Fare::query()->findOrFail($row['fare_id']) : null;
        $this->createSegments($passenger, $flights, $cabin, $type, $bucket, $returnBucket, $returnAllocation, $directFare, $row, $waitlisted, $goShow);

        if ($infantData) {
            $infant = $booking->passengers()->create($infantData + [
                'type' => PassengerType::Infant,
                'infant_of_passenger_id' => $passenger->id,
            ]);
            $this->createSegments($infant, $flights, $cabin, PassengerType::Infant, $bucket, $returnBucket, $returnAllocation, $directFare, [], $waitlisted, $goShow);
        }

        return $waitlisted;
    }

    /**
     * @param  Collection<int, Flight>  $flights
     * @param  array<string, mixed>  $row
     */
    private function createSegments(
        Passenger $passenger, Collection $flights, CabinClass $cabin, PassengerType $type,
        ?AgencyAllocationFare $bucket, ?AgencyAllocationFare $returnBucket, ?AgencyAllocation $returnAllocation,
        ?Fare $directFare, array $row, bool $waitlisted, bool $goShow,
    ): void {
        $ticket = $this->tickets->for($flights->first()->airline, $passenger);

        foreach ($flights->values() as $index => $flight) {
            $isReturnAllocationFlight = $returnAllocation && $flight->id === $returnAllocation->outbound_flight_id;
            $segmentBucket = $isReturnAllocationFlight ? $returnBucket : $bucket;
            // A round-trip price is charged once, on the first coupon it covers.
            $charged = $index === 0 || $isReturnAllocationFlight;
            $price = $charged ? ($segmentBucket?->priceFor($type) ?? $directFare?->priceFor($type)) : null;

            $segment = $passenger->segments()->create([
                'flight_id' => $flight->id,
                'segment_no' => $index + 1,
                'cabin_class' => $cabin,
                'fare_id' => $segmentBucket?->fare_id ?? $directFare?->id,
                'agency_allocation_fare_id' => $segmentBucket?->id,
                'fare_class' => $segmentBucket?->fareClass() ?? $directFare?->fare_class,
                'ticket_number' => $ticket,
                'fare_amount' => $price['fare'] ?? 0,
                'tax_amount' => $price['tax'] ?? 0,
                'agency_sale_amount' => $charged ? ($row['agency_sale_amount'] ?? null) : null,
                'currency' => $flight->airline->currency,
                'status' => $waitlisted ? SegmentStatus::Waitlisted : SegmentStatus::Booked,
                'is_go_show' => $goShow,
            ]);

            if (! empty($row['ssr_codes'])) {
                $this->syncSsr($segment, $row['ssr_codes'], $flight->airline_id);
            }

            if (! empty($row['prepaid_baggage']['pieces']) || ! empty($row['prepaid_baggage']['weight_kg'])) {
                $segment->prepaidBaggage()->create([
                    'pieces' => $row['prepaid_baggage']['pieces'] ?? 0,
                    'weight_kg' => $row['prepaid_baggage']['weight_kg'] ?? 0,
                    'created_by' => auth()->id(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, Flight>
     */
    private function resolveFlights(array $data, ?AgencyAllocation $allocation, ?AgencyAllocation $returnAllocation): Collection
    {
        $ids = $allocation
            ? array_merge($allocation->flightIds(), $returnAllocation ? [$returnAllocation->outbound_flight_id] : [])
            : ($data['flight_ids'] ?? []);

        if ($ids === []) {
            throw BusinessRuleException::make('no_flights', 'A booking needs at least one flight.');
        }

        $flights = Flight::query()->with(['airline', 'aircraft', 'origin', 'destination'])->whereIn('id', $ids)->get()
            ->sortBy('scheduled_departure_at')->values();

        if ($flights->count() !== count(array_unique($ids))) {
            throw BusinessRuleException::make('flight_not_found', 'One of the flights does not exist.');
        }

        if ($flights->pluck('airline_id')->unique()->count() > 1) {
            throw BusinessRuleException::make('different_airlines', 'All flights of a booking must belong to one airline.');
        }

        return $flights;
    }

    private function assertAllocationUsable(AgencyAllocation $allocation, User $actor): void
    {
        if ($actor->agency_id && (int) $actor->agency_id !== (int) $allocation->agency_id) {
            throw BusinessRuleException::make('allocation_other_agency', 'This allocation belongs to another agency.', status: 403);
        }

        if ($actor->isAgencyUser() && ! $allocation->isOpenForNames()) {
            throw BusinessRuleException::make('names_deadline_passed', 'The name list deadline for this allocation has passed.');
        }
    }

    private function resolveBucket(?AgencyAllocation $allocation, ?int $bucketId, CabinClass $cabin): ?AgencyAllocationFare
    {
        if (! $allocation) {
            return null;
        }

        if (! $allocation->uses_fare_quotas) {
            return null;
        }

        if (! $bucketId) {
            throw BusinessRuleException::make('fare_required', 'Choose a fare for this allocation.');
        }

        $bucket = $allocation->fares->firstWhere('id', $bucketId);
        if (! $bucket) {
            throw BusinessRuleException::make('fare_not_in_allocation', 'The chosen fare does not belong to this allocation.');
        }

        if ($bucket->cabin_class !== $cabin) {
            throw BusinessRuleException::make('fare_wrong_cabin', 'The chosen fare is for another cabin.');
        }

        return $bucket;
    }

    /** @param array<int> $flightIds */
    private function assertNotDuplicate(array $flightIds, ?string $documentNumber, ?int $exceptPassengerId = null): void
    {
        if (! $documentNumber) {
            return;
        }

        $existing = PassengerSegment::query()
            ->with('passenger.booking.agency')
            ->whereIn('flight_id', $flightIds)
            ->whereNotIn('status', [SegmentStatus::Cancelled])
            ->whereHas('passenger', fn ($q) => $q->where('document_number', $documentNumber)
                ->when($exceptPassengerId, fn ($x) => $x->whereKeyNot($exceptPassengerId)))
            ->first();

        if ($existing) {
            $by = $existing->passenger->booking->agency?->name ?? 'the airline';
            throw BusinessRuleException::make('duplicate_passenger',
                "Passenger with document {$documentNumber} is already booked on this flight (by {$by}, PNR {$existing->passenger->booking->pnr}).");
        }
    }

    private function assertInfantCapacity(Flight $flight): void
    {
        $max = $flight->aircraft?->max_infants;
        if (! $max) {
            return;
        }

        $infants = PassengerSegment::query()->where('flight_id', $flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled])
            ->whereHas('passenger', fn ($q) => $q->whereNotNull('infant_of_passenger_id'))
            ->count();

        if ($infants >= $max) {
            throw BusinessRuleException::make('infants_full', "Flight {$flight->flight_number} allows only {$max} infant(s).");
        }
    }

    /**
     * @param  Collection<int, PassengerSegment>  $segments
     * @param  array<string, mixed>  $data
     */
    private function changeCabinOrFare(Passenger $passenger, Collection $segments, array $data, ?AgencyAllocation $allocation): void
    {
        $first = $segments->first();
        $cabin = isset($data['cabin_class']) ? CabinClass::from($data['cabin_class']) : $first->cabin_class;
        $bucketId = array_key_exists('allocation_fare_id', $data) ? $data['allocation_fare_id'] : $first->agency_allocation_fare_id;

        if ($segments->contains(fn (PassengerSegment $s) => $s->isCheckedIn()) && $cabin !== $first->cabin_class) {
            throw BusinessRuleException::make('passenger_checked_in', 'Change the cabin by giving a new seat at check-in.');
        }

        $bucket = $this->resolveBucket($allocation, $bucketId, $cabin);
        if ($allocation && ($cabin !== $first->cabin_class || $bucket?->id !== $first->agency_allocation_fare_id)) {
            $this->allocations->assertCanTake($allocation, $cabin, $bucket, $passenger->id);
        }

        $directFare = ! $allocation && isset($data['fare_id']) ? Fare::query()->find($data['fare_id']) : null;
        $type = $passenger->type ?? PassengerType::Adult;

        foreach ($segments->values() as $index => $segment) {
            $price = $index === 0 ? ($bucket?->priceFor($type) ?? $directFare?->priceFor($type)) : null;
            $segment->update(array_filter([
                'cabin_class' => $cabin,
                'agency_allocation_fare_id' => $bucket?->id,
                'fare_id' => $bucket?->fare_id ?? $directFare?->id ?? $segment->fare_id,
                'fare_class' => $bucket?->fareClass() ?? $directFare?->fare_class ?? $segment->fare_class,
                'fare_amount' => $price['fare'] ?? null,
                'tax_amount' => $price['tax'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if ($passenger->infant) {
            PassengerSegment::query()->where('passenger_id', $passenger->infant->id)->update(['cabin_class' => $cabin->value]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $infantData
     * @param  Collection<int, PassengerSegment>  $segments
     */
    private function syncInfant(Passenger $adult, ?array $infantData, Collection $segments, ?AgencyAllocation $allocation): void
    {
        $infant = $adult->infant;

        if ($infantData === null) {
            if ($infant) {
                if ($infant->segments->contains(fn (PassengerSegment $s) => $s->isCheckedIn())) {
                    throw BusinessRuleException::make('passenger_checked_in', 'Cancel the check-in before removing the infant.');
                }
                $infant->segments()->update(['status' => SegmentStatus::Cancelled->value]);
                $infant->delete();
            }

            return;
        }

        $attributes = $this->passengerAttributes($infantData) + ['type' => PassengerType::Infant];

        if ($infant) {
            $infant->update($attributes);

            return;
        }

        foreach ($segments as $segment) {
            $this->assertInfantCapacity($segment->flight);
        }

        $infant = $adult->booking->passengers()->create($attributes + ['infant_of_passenger_id' => $adult->id]);
        $flights = $segments->pluck('flight');
        $first = $segments->first();
        $bucket = $first->allocationFare;
        $this->createSegments($infant, $flights, $first->cabin_class, PassengerType::Infant, $bucket, null, null,
            $allocation ? null : $first->fare, [], $first->status === SegmentStatus::Waitlisted, $first->is_go_show);
    }

    /** @param array<string> $codes */
    private function syncSsr(PassengerSegment $segment, array $codes, int $airlineId): void
    {
        $codes = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes)));

        $ids = SsrCode::query()->whereIn('code', $codes)
            ->whereIn('id', fn ($q) => $q->select('ssr_code_id')->from('airline_ssrs')->where('airline_id', $airlineId))
            ->pluck('id');

        if ($ids->count() !== count($codes)) {
            throw BusinessRuleException::make('ssr_not_offered', 'One of the SSR codes is not offered by this airline.');
        }

        $segment->ssrCodes()->sync($ids);
    }

    /**
     * Normalise passenger input: names & documents upper-case, empty strings to null.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function passengerAttributes(array $row): array
    {
        $attributes = [];
        foreach (self::PASSENGER_FIELDS as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            if ($value !== null && in_array($field, ['first_name', 'middle_name', 'last_name', 'nationality', 'document_number', 'document_issuing_country', 'person_code'], true)) {
                $value = mb_strtoupper($value);
            }
            $attributes[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $attributes;
    }

    private function channel(BookingSource $source): ActivityChannel
    {
        return match ($source) {
            BookingSource::Agency, BookingSource::Import => ActivityChannel::Agency,
            BookingSource::AgencyApi => ActivityChannel::AgencyApi,
            BookingSource::Counter => ActivityChannel::Dcs,
            BookingSource::Admin => ActivityChannel::Admin,
        };
    }

    /**
     * Bags can be accepted before the check-in — a passenger leaving the flight must not leave them behind.
     *
     * @param  Collection<int, PassengerSegment>  $segments
     */
    private function assertNoBaggage(Collection $segments, string $action): void
    {
        if (CheckedBaggage::query()->whereIn('passenger_segment_id', $segments->pluck('id'))->exists()) {
            throw BusinessRuleException::make('baggage_not_offloaded', "Remove the passenger's baggage before {$action}.");
        }
    }
}
