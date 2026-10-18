<?php

namespace App\Services\Baggage;

use App\Enums\BaggageRuleType;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BaggageRule;
use App\Models\CheckedBaggage;
use App\Models\Flight;
use App\Models\PassengerSegment;
use Illuminate\Support\Collection;

/**
 * Free allowance, excess weight and price of a bag — the only place this is calculated.
 * The counter used to send these numbers; now the API decides and the DCS only shows the quote.
 *
 *  - piece rule (1PC 23KG …): allowance = rule kg × pieces, excess = weight above it
 *  - combined rule: every passenger (and their check-in group) shares one free kg pool, rule kg × rule pieces each;
 *    a bag takes what is left of the pool, so 2 bags of 22 kg total fit 1PC 23KG and the last 1 kg stays free
 *  - excess rule (ssr EBAG or type "weight"): no allowance, the whole weight is excess
 *  - prepaid (XBAG) is already paid → price 0; other paid rules → rule price × pieces
 *  - excess is charged per kg (flight.excess_baggage_price_per_kg) and capped per passenger
 *    by flight.excess_baggage_max_kg
 */
class BaggagePricing
{
    /** @return array{free_allowance_kg: float, excess_kg: float, excess_price: float, price: float, excess_price_per_kg: float, remaining_allowance_kg: ?float} */
    public function quote(PassengerSegment $segment, BaggageRule $rule, int $pieces, float $weightKg, ?Flight $flight = null): array
    {
        $flight ??= $segment->flight;
        $perKg = (float) $flight->excess_baggage_price_per_kg;

        $isExcessRule = $rule->isSsr(BaggageRule::SSR_EXCESS) || $rule->type === BaggageRuleType::Weight;
        $remaining = $rule->isCombined() ? $this->remainingPool($segment, $rule) : null;
        $allowance = match (true) {
            $isExcessRule => 0.0,
            $rule->isCombined() => min($weightKg, $remaining),
            default => (float) $rule->weight_kg * $pieces,
        };
        $excessKg = round(max(0.0, $weightKg - $allowance), 1);

        $max = $flight->excess_baggage_max_kg !== null ? (float) $flight->excess_baggage_max_kg : null;
        if ($max !== null && $excessKg > 0 && $this->excessSoFar($segment) + $excessKg > $max) {
            throw BusinessRuleException::make('excess_limit_exceeded',
                "Excess baggage is limited to {$max} kg per passenger on this flight.");
        }

        $price = $rule->isSsr(BaggageRule::SSR_PREPAID) || $rule->isSsr(BaggageRule::SSR_FREE)
            ? 0.0
            : round((float) $rule->price * $pieces, 2);

        return [
            'free_allowance_kg' => $allowance,
            'excess_kg' => $excessKg,
            'excess_price' => round($excessKg * $perKg, 2),
            'price' => $price,
            'excess_price_per_kg' => $perKg,
            'remaining_allowance_kg' => $remaining !== null ? round($remaining - $allowance, 1) : null,
        ];
    }

    /** Free kg still unused under a combined rule by the passenger or their check-in group. */
    public function remainingPool(PassengerSegment $segment, BaggageRule $rule): float
    {
        $members = $this->allowanceSegments($segment, $rule);
        $used = (float) CheckedBaggage::query()->whereIn('passenger_segment_id', $members->pluck('id'))
            ->where('baggage_rule_id', $rule->id)->sum('free_allowance_kg');

        return max(0.0, round($rule->freeWeightPerPassenger() * $members->count() - $used, 1));
    }

    /**
     * Segments whose allowance a bag may use: the passenger alone, or every confirmed adult of their check-in
     * group on this flight that the rule applies to (same cabin).
     *
     * @return Collection<int, PassengerSegment>
     */
    public function allowanceSegments(PassengerSegment $segment, BaggageRule $rule): Collection
    {
        $segment->loadMissing('prepaidBaggage');
        if (! $segment->checkin_group_id) {
            return collect([$segment]);
        }

        $members = PassengerSegment::query()->with('prepaidBaggage')
            ->where('flight_id', $segment->flight_id)
            ->where('checkin_group_id', $segment->checkin_group_id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->get()
            ->filter(fn (PassengerSegment $s) => $rule->cabin_class === null || $s->cabin_class->value === $rule->cabin_class)
            ->values();

        return $members->contains('id', $segment->id) ? $members : $members->push($segment);
    }

    /** Excess kg already accepted for this passenger segment */
    public function excessSoFar(PassengerSegment $segment): float
    {
        return (float) CheckedBaggage::query()->where('passenger_segment_id', $segment->id)->get()
            ->sum(fn (CheckedBaggage $b) => max(0, (float) $b->weight_kg - (float) $b->free_allowance_kg));
    }
}
