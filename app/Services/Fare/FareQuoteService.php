<?php

namespace App\Services\Fare;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\TripType;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\Fare;
use App\Models\FareCombination;
use App\Models\Flight;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Published fares (fare settings) a flight can be sold at, with every fare rule applied:
 * route, validity, active / visible fare base, active booking class that is not an upgrade class,
 * advance booking days, min / max stay (RT), booking class availability (or waitlist) and fare combinations.
 */
class FareQuoteService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Fare bases an allocation sells at (published fares): RT fares when it has a return flight, OW otherwise.
     *
     * @param  array<int, CabinClass>|null  $cabins  only these cabins (null = all)
     * @return array<int, array<string, mixed>>
     */
    public function forAllocation(AgencyAllocation $allocation, ?array $cabins = null): array
    {
        $allocation->loadMissing(['outboundFlight.origin', 'returnFlight', 'cabins']);
        $cabins ??= $allocation->cabins->pluck('cabin_class')->all();

        // a fixed block sells its own seats: booking class limits do not apply to it
        return $this->quotes($allocation->outboundFlight, $allocation->returnFlight, $cabins, $allocation->isPool());
    }

    /**
     * @param  array<int, CabinClass>  $cabins
     * @return array<int, array<string, mixed>>
     */
    public function quotes(Flight $outbound, ?Flight $return, array $cabins, bool $withInventory = true): array
    {
        $cabinValues = array_map(fn (CabinClass $c) => $c->value, $cabins);

        return $this->candidates($outbound, $return)
            ->filter(fn (Fare $fare) => in_array($fare->fareClass->rbd->cabin_class->value, $cabinValues, true)
                && $this->problem($fare, $outbound, $return) === null)
            ->map(function (Fare $fare) use ($outbound, $return, $withInventory) {
                $class = $fare->fareClass;
                $available = $withInventory ? $this->available($fare, $outbound, $return) : null;
                if ($available !== null && $available < 1 && ! $class->waitlist) {
                    return null;
                }

                return [
                    'id' => $fare->id,
                    'fare_id' => $fare->id,
                    'fare_class' => $class->booking_code,
                    'rbd_code' => $class->rbd->code,
                    'cabin_class' => $class->rbd->cabin_class->value,
                    'fare_family' => $class->rbd->fareFamily
                        ? ['code' => $class->rbd->fareFamily->code, 'name' => $class->rbd->fareFamily->name, 'rank' => $class->rbd->fareFamily->rank]
                        : null,
                    'trip_type' => $class->trip_type->value,
                    'seats' => 0,
                    'sold' => 0,
                    'held' => 0,
                    // booking class seats left (null = not inventory-controlled, or a fixed block)
                    'remaining' => $available,
                    'waitlist' => $available !== null && $available < 1,
                    'price' => $fare->priceFor(PassengerType::Adult),
                    'published' => true,
                    'rules' => [
                        'advance_booking_days' => $class->rbd->advance_booking_days,
                        'min_stay_days' => $class->min_stay_days,
                        'max_stay_days' => $class->max_stay_days,
                        'ticket_time_limit_hours' => $class->ticket_time_limit_hours,
                        'rule' => $class->rule,
                    ],
                ];
            })
            ->filter()
            ->sortBy(fn (array $q) => (float) $q['price']['fare'] + (float) $q['price']['tax'])
            ->values()
            ->all();
    }

    /** Throws when the fare cannot be sold on these flights in this cabin. */
    public function assertBookable(Fare $fare, Flight $outbound, ?Flight $return, CabinClass $cabin): void
    {
        $fare->loadMissing('fareClass.rbd');
        if (! $fare->fareClass) {
            throw BusinessRuleException::make('fare_not_published', 'This fare has no fare base: choose a published fare.');
        }
        if ($fare->fareClass->rbd->cabin_class !== $cabin) {
            throw BusinessRuleException::make('fare_wrong_cabin', 'The chosen fare is for another cabin.');
        }
        if ($problem = $this->problem($fare, $outbound, $return)) {
            throw BusinessRuleException::make('fare_not_bookable', "Fare {$fare->fareClass->booking_code}: {$problem}");
        }
    }

    /** Outbound and return fare bases must be the same, or saved as a fare combination. */
    public function assertCombinable(Fare $outbound, Fare $return): void
    {
        $a = (int) $outbound->fare_class_id;
        $b = (int) $return->fare_class_id;
        if ($a && $b && $a !== $b && ! FareCombination::query()->where(fn ($q) => $q->pair($a, $b))->exists()) {
            throw BusinessRuleException::make('fares_not_combinable',
                "Fare {$outbound->fareClass?->booking_code} cannot be combined with {$return->fareClass?->booking_code}.");
        }
    }

    /** Seats left in the fare's booking class on every flight (null = not inventory-controlled). */
    public function available(Fare $fare, Flight $outbound, ?Flight $return): ?int
    {
        $class = $fare->fareClass;
        $counts = collect(array_filter([$outbound, $return]))
            ->map(fn (Flight $f) => $this->inventory->available($f, $class->rbd->cabin_class, $class->rbd->code))
            ->filter(fn ($n) => $n !== null);

        return $counts->isEmpty() ? null : (int) $counts->min();
    }

    /** @return Collection<int, Fare> active fare-base fares of the flight's route and trip type */
    private function candidates(Flight $outbound, ?Flight $return): Collection
    {
        return Fare::query()
            ->with(['fareClass.rbd.fareFamily', 'fareClass.discounts'])
            ->where('airline_id', $outbound->airline_id)
            ->whereNotNull('fare_class_id')
            ->where('origin_airport_id', $outbound->origin_airport_id)
            ->where('destination_airport_id', $outbound->destination_airport_id)
            ->where('trip_type', $return ? TripType::RoundTrip : TripType::OneWay)
            ->where('is_active', true)
            ->get();
    }

    /** Why the fare cannot be sold on these flights, or null when it can. */
    private function problem(Fare $fare, Flight $outbound, ?Flight $return): ?string
    {
        $class = $fare->fareClass;
        $rbd = $class->rbd;
        $departure = $this->localDate($outbound);

        return match (true) {
            (int) $fare->airline_id !== (int) $outbound->airline_id => 'another airline',
            ! $fare->is_active || ! $class->is_active || ! $class->is_visible => 'not for sale',
            ! $rbd->is_active => "booking class {$rbd->code} is closed",
            $rbd->is_upgrade => "booking class {$rbd->code} is for upgrades only",
            (int) $fare->origin_airport_id !== (int) $outbound->origin_airport_id
                || (int) $fare->destination_airport_id !== (int) $outbound->destination_airport_id => 'another route',
            ($class->trip_type === TripType::RoundTrip) !== ($return !== null) => $return ? 'one way fare on a round trip' : 'round trip fare without a return flight',
            // local calendar dates compared as strings (valid_from / valid_to are plain dates)
            $departure->toDateString() < $fare->valid_from->toDateString()
                || $departure->toDateString() > $fare->valid_to->toDateString() => 'not valid on the departure date',
            $rbd->advance_booking_days && $this->today($outbound)->diffInDays($departure, false) < $rbd->advance_booking_days
                => "must be booked at least {$rbd->advance_booking_days} day(s) before departure",
            $return && $class->min_stay_days && $departure->diffInDays($this->localDate($return), false) < $class->min_stay_days
                => "minimum stay is {$class->min_stay_days} day(s)",
            $return && $class->max_stay_days && $departure->diffInDays($this->localDate($return), false) > $class->max_stay_days
                => "maximum stay is {$class->max_stay_days} day(s)",
            default => null,
        };
    }

    private function localDate(Flight $flight): Carbon
    {
        return $flight->departureAt()->copy()->timezone($this->timezone($flight))->startOfDay();
    }

    private function today(Flight $flight): Carbon
    {
        return now($this->timezone($flight))->startOfDay();
    }

    private function timezone(Flight $flight): string
    {
        $flight->loadMissing('origin');

        return $flight->origin?->timezone ?: config('flightmode.timezone');
    }
}
