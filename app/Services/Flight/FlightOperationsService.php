<?php

namespace App\Services\Flight;

use App\Enums\ActivityChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Models\ServiceTemplate;
use App\Services\ActivityLogger;
use App\Services\Integrations\Chinggis\ChinggisSync;
use Illuminate\Support\Facades\DB;

/**
 * Flight life cycle and operational settings (legacy "flight manager" + DCS open / close).
 *
 *   scheduled → checkin_open → checkin_closed → gate_closed → departed
 *        (cancelled from any state before departure)
 */
class FlightOperationsService
{
    /** Fields a flight series writes on its flights. */
    private const SERIES_FIELDS = [
        'aircraft_id', 'flight_number', 'origin_airport_id', 'destination_airport_id', 'scheduled_departure_at',
        'scheduled_arrival_at', 'gate', 'excess_baggage_max_kg', 'excess_baggage_price_per_kg',
    ];

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly ChinggisSync $chinggis,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(Flight $flight, array $data): Flight
    {
        $original = $flight->getOriginal();
        $flight->fill($data);
        // a series flight changed by hand keeps its own schedule when the series is saved again
        if ($flight->flight_series_id && $flight->isDirty(self::SERIES_FIELDS)) {
            $flight->is_series_override = true;
        }
        $flight->save();

        $this->logger->logChanges(ActivityChannel::Admin, 'flight.updated', $flight, $original,
            $flight->flight_number, $flight);

        if ($flight->wasChanged(['scheduled_departure_at', 'scheduled_arrival_at', 'flight_number', 'origin_airport_id', 'destination_airport_id'])) {
            $this->chinggis->flightChanged($flight);
        }

        return $flight;
    }

    public function delete(Flight $flight): void
    {
        if ($flight->segments()->exists()) {
            throw BusinessRuleException::make('flight_has_passengers', 'A flight with passengers cannot be deleted; cancel it instead.');
        }

        if ($flight->outboundAllocations()->exists() || $flight->returnAllocations()->exists()) {
            throw BusinessRuleException::make('flight_has_allocations', 'Remove the agency allocations of this flight first.');
        }

        $flight->delete();
        $this->logger->log(ActivityChannel::Admin, 'flight.deleted', $flight->flight_number, $flight, $flight);
    }

    public function openCheckin(Flight $flight): Flight
    {
        $this->assertNotFinal($flight);
        if ($flight->status === FlightStatus::CheckinOpen) {
            return $flight;
        }

        return $this->transition($flight, FlightStatus::CheckinOpen, 'flight.checkin_opened', ActivityChannel::Dcs);
    }

    /**
     * Close the counter. When online check-in stays open the flight goes back to "scheduled"
     * (legacy behaviour: the counter can be re-opened later).
     */
    public function closeCheckin(Flight $flight, bool $closeOnline): Flight
    {
        if ($flight->status !== FlightStatus::CheckinOpen) {
            throw BusinessRuleException::make('checkin_not_open', 'Check-in is not open.');
        }

        return $this->transition($flight, $closeOnline ? FlightStatus::CheckinClosed : FlightStatus::Scheduled,
            'flight.checkin_closed', ActivityChannel::Dcs, ['online_closed' => $closeOnline]);
    }

    public function closeGate(Flight $flight): Flight
    {
        if (! in_array($flight->status, [FlightStatus::CheckinOpen, FlightStatus::CheckinClosed], true)) {
            throw BusinessRuleException::make('invalid_status', 'The gate can be closed only after check-in has started.');
        }

        return $this->transition($flight, FlightStatus::GateClosed, 'flight.gate_closed', ActivityChannel::Admin);
    }

    /** Record the departure times; boarded passengers become "flown". */
    public function markDeparted(Flight $flight, ?string $offBlockAt, ?string $takeoffAt, ?string $estimatedArrivalAt, ?string $reason): Flight
    {
        $this->assertNotFinal($flight);

        return DB::transaction(function () use ($flight, $offBlockAt, $takeoffAt, $estimatedArrivalAt, $reason) {
            $flight->fill([
                'off_block_at' => $offBlockAt,
                'takeoff_at' => $takeoffAt,
                'estimated_arrival_at' => $estimatedArrivalAt,
                'status_reason' => $reason,
            ]);
            $this->transition($flight, FlightStatus::Departed, 'flight.departed', ActivityChannel::Admin);

            PassengerSegment::query()->where('flight_id', $flight->id)->where('status', SegmentStatus::Boarded->value)
                ->update(['status' => SegmentStatus::Flown->value]);

            return $flight;
        });
    }

    public function cancel(Flight $flight, string $reason): Flight
    {
        $this->assertNotFinal($flight);
        $flight->status_reason = $reason;

        return $this->transition($flight, FlightStatus::Cancelled, 'flight.cancelled', ActivityChannel::Admin);
    }

    public function setGate(Flight $flight, ?string $gate): Flight
    {
        return $this->update($flight, ['gate' => $gate]);
    }

    public function setEstimatedDeparture(Flight $flight, ?string $estimatedDepartureAt): Flight
    {
        return $this->update($flight, ['estimated_departure_at' => $estimatedDepartureAt]);
    }

    /** @param array<int> $userIds */
    public function setGateAgents(Flight $flight, array $userIds): Flight
    {
        $before = $flight->gateAgents()->pluck('users.id')->all();
        $flight->gateAgents()->sync($userIds);

        $this->logger->log(ActivityChannel::Admin, 'flight.gate_agents_changed', null, $flight, $flight,
            properties: ['changes' => ['gate_agents' => [$before, array_values($userIds)]]]);

        return $flight->load('gateAgents');
    }

    /**
     * @param array{online_checkin_enabled: bool, online_checkin_opens_minutes?: ?int, online_checkin_closes_minutes?: ?int,
     *              seat_assignment_profile_id?: ?int, baggage_rule_ids?: array<int>, seat_price_ids?: array<int>} $data
     */
    public function setOnlineCheckin(Flight $flight, array $data): Flight
    {
        return DB::transaction(function () use ($flight, $data) {
            $this->update($flight, collect($data)->only([
                'online_checkin_enabled', 'online_checkin_opens_minutes', 'online_checkin_closes_minutes', 'seat_assignment_profile_id',
            ])->all());

            if (array_key_exists('baggage_rule_ids', $data)) {
                $flight->baggageRules()->sync($data['baggage_rule_ids'] ?? []);
            }
            if (array_key_exists('seat_price_ids', $data)) {
                $flight->seatPrices()->sync($data['seat_price_ids'] ?? []);
            }

            return $flight->load(['baggageRules', 'seatPrices']);
        });
    }

    /**
     * Copy a service template (online check-in window, seat profile, baggage & seat products) to flights.
     *
     * @param  iterable<Flight>  $flights
     */
    public function applyTemplate(ServiceTemplate $template, iterable $flights): int
    {
        $template->loadMissing(['baggageRules', 'seatPrices']);
        $count = 0;

        foreach ($flights as $flight) {
            if ($template->aircraft_id !== null && (int) $flight->aircraft_id !== (int) $template->aircraft_id) {
                throw BusinessRuleException::make('template_other_aircraft',
                    "Template is for another aircraft than flight {$flight->flight_number}.");
            }

            // An any-aircraft template has no seat profile: the flight keeps its own
            $this->setOnlineCheckin($flight, [
                'online_checkin_enabled' => true,
                'online_checkin_opens_minutes' => $template->checkin_opens_minutes,
                'online_checkin_closes_minutes' => $template->checkin_closes_minutes,
                'baggage_rule_ids' => $template->baggageRules->pluck('id')->all(),
                'seat_price_ids' => $template->seatPrices->pluck('id')->all(),
            ] + ($template->aircraft_id === null ? [] : ['seat_assignment_profile_id' => $template->seat_assignment_profile_id]));
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $properties */
    private function transition(Flight $flight, FlightStatus $to, string $event, ActivityChannel $channel, array $properties = []): Flight
    {
        $from = $flight->status;
        $flight->status = $to;
        $flight->save();

        $this->logger->log($channel, $event, "{$flight->flight_number}: {$from?->value} → {$to->value}", $flight, $flight,
            properties: $properties + ['changes' => ['status' => [$from?->value, $to->value]]]);

        return $flight;
    }

    private function assertNotFinal(Flight $flight): void
    {
        if (in_array($flight->status, [FlightStatus::Departed, FlightStatus::Cancelled], true)) {
            throw BusinessRuleException::make('flight_closed', 'This flight has already departed or was cancelled.');
        }
    }
}
