<?php

namespace App\Services\Baggage;

use App\Enums\ActivityChannel;
use App\Enums\BaggageType;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BaggageRule;
use App\Models\BaggageTag;
use App\Models\CheckedBaggage;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\ActivityLogger;
use App\Services\Checkin\CheckinService;
use App\Services\Checkin\PassengerScreening;
use Illuminate\Support\Facades\DB;

/**
 * Baggage acceptance at the counter and bag tag numbering.
 *
 * Tag number: "1" + airline numeric code (3) + passenger boarding sequence (3) + bag sequence on the flight (3).
 * Crew bags:  "1" + airline numeric code (3) + "999" + bag sequence (3).
 */
class BaggageService
{
    public const CREW_SEQUENCE = '999';

    public function __construct(
        private readonly CheckinService $checkin,
        private readonly ActivityLogger $logger,
        private readonly BaggagePricing $pricing,
        private readonly PassengerScreening $screening,
    ) {}

    /**
     * Allowance / excess / price come from BaggagePricing, never from the client. Excess must be paid
     * (payment_reference) or waived (waive_reason + waive_authorized_by) before the bag is tagged.
     *
     * @param array{baggage_rule_id: int, excess_baggage_rule_id?: ?int, pieces: int, weight_kg: float, description?: ?string,
     *              payment_reference?: ?string, waive_reason?: ?string, waive_authorized_by?: ?string} $data
     */
    public function accept(PassengerSegment $segment, array $data): CheckedBaggage
    {
        return DB::transaction(function () use ($segment, $data) {
            $flight = Flight::query()->with('airline')->lockForUpdate()->findOrFail($segment->flight_id);
            $this->checkin->assertCounterOpen($flight);

            $segment = PassengerSegment::query()->with(['passenger', 'prepaidBaggage'])->findOrFail($segment->id);
            // bags may come before the check-in (legacy counter: weigh first, then the seat)
            if (! in_array($segment->status, [SegmentStatus::Booked, SegmentStatus::CheckedIn], true)) {
                throw BusinessRuleException::make('baggage_not_allowed', 'Baggage can be accepted only for a booked or checked-in passenger.');
            }
            // a banned passenger is not checked in, so their bags are not taken either
            if ($segment->status === SegmentStatus::Booked && $this->screening->isBanned($segment->passenger, $flight)) {
                throw BusinessRuleException::make('passenger_banned', 'This passenger is on the ban list — baggage cannot be accepted.');
            }
            // the tag number carries the boarding sequence — given now, kept by the check-in that follows
            if (! $segment->boarding_sequence) {
                $segment->update(['boarding_sequence' => $this->checkin->nextBoardingSequence($flight)]);
            }

            $rule = BaggageRule::query()->where('airline_id', $flight->airline_id)->findOrFail($data['baggage_rule_id']);
            $this->assertPieceLimit($segment, $rule, (int) $data['pieces']);
            $quote = $this->pricing->quote($segment, $rule, (int) $data['pieces'], (float) $data['weight_kg'], $flight);
            $settlement = $this->settleExcess($quote['excess_price'], $data);

            $baggage = CheckedBaggage::create([
                'flight_id' => $flight->id,
                'passenger_segment_id' => $segment->id,
                'type' => BaggageType::Passenger,
                'baggage_rule_id' => $rule->id,
                'excess_baggage_rule_id' => $data['excess_baggage_rule_id'] ?? null,
                'pieces' => $data['pieces'],
                'weight_kg' => $data['weight_kg'],
                'free_allowance_kg' => $quote['free_allowance_kg'],
                'price' => $quote['price'],
                'excess_price' => $quote['excess_price'],
                'currency' => $flight->airline->currency,
                'checkin_group_id' => $segment->checkin_group_id,
                ...$settlement,
                'description' => $data['description'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $paxSequence = str_pad((string) $segment->boarding_sequence, 3, '0', STR_PAD_LEFT);
            $this->createTags($flight, $baggage, $paxSequence);

            $this->logger->log(ActivityChannel::Dcs, 'baggage.added',
                'Bag tags: '.$baggage->tags->pluck('tag_number')->implode(', '),
                $baggage, $flight, $segment->passenger_id);

            return $baggage->load('tags', 'rule');
        });
    }

    /**
     * Payment / waiver columns for an excess price. Nothing is needed when there is no excess.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settleExcess(float $excessPrice, array $data): array
    {
        if ($excessPrice <= 0) {
            return [];
        }

        $reference = trim((string) ($data['payment_reference'] ?? ''));
        $reason = trim((string) ($data['waive_reason'] ?? ''));

        if ($reference !== '') {
            return ['payment_reference' => $reference];
        }

        if ($reason !== '') {
            if (trim((string) ($data['waive_authorized_by'] ?? '')) === '') {
                throw BusinessRuleException::make('waive_authorizer_required', 'Enter who allowed the excess baggage waiver.');
            }

            return [
                'waived_amount' => $excessPrice,
                'waive_reason' => $reason,
                'waive_authorized_by' => trim((string) $data['waive_authorized_by']),
                'waived_by' => auth()->id(),
            ];
        }

        throw BusinessRuleException::make('excess_payment_required',
            'Excess baggage must be paid (billing / transaction number) or waived with a reason.');
    }

    /** @param array{weight_kg: float, pieces?: int, description?: ?string} $data */
    public function acceptCrew(Flight $flight, array $data): CheckedBaggage
    {
        return DB::transaction(function () use ($flight, $data) {
            $flight = Flight::query()->with('airline')->lockForUpdate()->findOrFail($flight->id);
            $this->checkin->assertCounterOpen($flight);

            $baggage = CheckedBaggage::create([
                'flight_id' => $flight->id,
                'passenger_segment_id' => null,
                'type' => BaggageType::Crew,
                'crew_name' => isset($data['crew_name']) ? mb_strtoupper(trim($data['crew_name'])) : null,
                'pieces' => $data['pieces'] ?? 1,
                'weight_kg' => $data['weight_kg'],
                'currency' => $flight->airline->currency,
                'description' => $data['description'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->createTags($flight, $baggage, self::CREW_SEQUENCE);

            $this->logger->log(ActivityChannel::Dcs, 'baggage.crew_added',
                'Crew bag tags: '.$baggage->tags->pluck('tag_number')->implode(', ').($baggage->crew_name ? " ({$baggage->crew_name})" : ''), $baggage, $flight);

            return $baggage->load('tags');
        });
    }

    public function remove(CheckedBaggage $baggage): void
    {
        DB::transaction(function () use ($baggage) {
            $flight = Flight::query()->lockForUpdate()->findOrFail($baggage->flight_id);
            $this->checkin->assertCounterOpen($flight);

            $tags = $baggage->tags()->pluck('tag_number')->implode(', ');
            $passengerId = $baggage->segment?->passenger_id;
            $baggage->delete();

            $this->logger->log(ActivityChannel::Dcs, 'baggage.removed', "Bag tags: {$tags}", null, $flight, $passengerId);
        });
    }

    /** @return array{pieces: int, weight_kg: float, passengers: int} */
    public function totals(Flight $flight): array
    {
        $row = CheckedBaggage::query()->where('flight_id', $flight->id)
            ->selectRaw('COALESCE(SUM(pieces), 0) as pieces, COALESCE(SUM(weight_kg), 0) as weight, COUNT(DISTINCT passenger_segment_id) as passengers')
            ->first();

        return [
            'pieces' => (int) $row->pieces,
            'weight_kg' => (float) $row->weight,
            'passengers' => (int) $row->passengers,
        ];
    }

    /**
     * Piece limit of the rule. A check-in group shares its baggage (legacy Group Modal): the limit is the sum of
     * every member's limit and bags accepted for any member count against it. Used by accept and the quote.
     */
    public function assertPieceLimit(PassengerSegment $segment, BaggageRule $rule, int $pieces): void
    {
        $members = $this->pricing->allowanceSegments($segment, $rule);

        $limit = 0;
        foreach ($members as $member) {
            $memberLimit = $rule->pieceLimit((int) $member->prepaidBaggage->sum('pieces'));
            if ($memberLimit === null) {
                return; // the rule has no piece limit
            }
            $limit += $memberLimit;
        }

        $already = (int) CheckedBaggage::query()->whereIn('passenger_segment_id', $members->pluck('id'))
            ->where('baggage_rule_id', $rule->id)->sum('pieces');

        if ($already + $pieces > $limit) {
            $scope = $members->count() > 1 ? " for the group of {$members->count()}" : '';
            $left = max(0, $limit - $already);
            throw BusinessRuleException::make('baggage_limit_exceeded',
                "Baggage limit for {$rule->name} exceeded ({$limit} piece(s) allowed{$scope}, {$left} left).");
        }
    }

    private function createTags(Flight $flight, CheckedBaggage $baggage, string $paxSequence): void
    {
        $prefix = $flight->airline->numeric_code;
        if (! $prefix) {
            throw BusinessRuleException::make('airline_numeric_code_missing', 'The airline has no numeric code for bag tags.');
        }

        $sequence = (int) BaggageTag::query()->where('flight_id', $flight->id)->max('sequence');

        for ($i = 0; $i < $baggage->pieces; $i++) {
            $sequence++;
            $baggage->tags()->create([
                'flight_id' => $flight->id,
                'sequence' => $sequence,
                'tag_number' => '1'.$prefix.$paxSequence.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            ]);
        }
    }
}
