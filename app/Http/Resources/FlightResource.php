<?php

namespace App\Http\Resources;

use App\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Flight */
class FlightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $originTz = $this->relationLoaded('origin') ? $this->origin->timezone : null;
        $destinationTz = $this->relationLoaded('destination') ? $this->destination->timezone : null;

        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'airline' => $this->whenLoaded('airline', fn () => ['id' => $this->airline->id, 'name' => $this->airline->name, 'iata_code' => $this->airline->iata_code]),
            'aircraft' => $this->whenLoaded('aircraft', fn () => $this->aircraft ? [
                'id' => $this->aircraft->id, 'registration' => $this->aircraft->registration,
                'type' => $this->aircraft->type, 'total_seats' => $this->aircraft->total_seats,
            ] : null),
            'aircraft_id' => $this->aircraft_id,
            'flight_series_id' => $this->flight_series_id,
            'is_series_override' => (bool) $this->is_series_override,
            'service_type' => $this->service_type?->value ?? 'scheduled',
            'flight_number' => $this->flight_number,
            'origin' => new AirportResource($this->whenLoaded('origin')),
            'destination' => new AirportResource($this->whenLoaded('destination')),
            'scheduled_departure_at' => $this->scheduled_departure_at->toIso8601String(),
            'scheduled_arrival_at' => $this->scheduled_arrival_at->toIso8601String(),
            'estimated_departure_at' => $this->estimated_departure_at?->toIso8601String(),
            'departure_local' => $this->local($this->departureAt(), $originTz),
            'scheduled_departure_local' => $this->local($this->scheduled_departure_at, $originTz),
            'scheduled_arrival_local' => $this->local($this->scheduled_arrival_at, $destinationTz),
            'off_block_at' => $this->off_block_at?->toIso8601String(),
            'takeoff_at' => $this->takeoff_at?->toIso8601String(),
            'estimated_arrival_at' => $this->estimated_arrival_at?->toIso8601String(),
            'terminal' => $this->terminal,
            'gate' => $this->gate,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'is_published' => $this->is_published,
            'excess_baggage_max_kg' => $this->excess_baggage_max_kg,
            'excess_baggage_price_per_kg' => $this->excess_baggage_price_per_kg,
            'online_checkin' => [
                'enabled' => $this->online_checkin_enabled,
                'opens_minutes' => $this->online_checkin_opens_minutes,
                'closes_minutes' => $this->online_checkin_closes_minutes,
                'seat_assignment_profile_id' => $this->seat_assignment_profile_id,
                'baggage_rule_ids' => $this->whenLoaded('baggageRules', fn () => $this->baggageRules->pluck('id')),
                'seat_price_ids' => $this->whenLoaded('seatPrices', fn () => $this->seatPrices->pluck('id')),
                'baggage_rules' => $this->whenLoaded('baggageRules', fn () => $this->baggageRules->pluck('name')),
                'seat_assignment_profile' => $this->whenLoaded('seatAssignmentProfile', fn () => $this->seatAssignmentProfile?->name),
                'seat_prices_count' => $this->whenCounted('seatPrices'),
            ],
            'blocked_seats_count' => $this->whenCounted('seatBlocks'),
            'gate_agents' => $this->whenLoaded('gateAgents', fn () => $this->gateAgents->map->only(['id', 'name'])),
            'segments_count' => $this->whenCounted('segments'),
            // DCS departures board (Dcs\FlightController::index)
            'passenger_counts' => $this->when(isset($this->booked_count), fn () => [
                'booked' => (int) $this->booked_count,
                'checked_in' => (int) $this->checked_in_count,
                'boarded' => (int) $this->boarded_count,
            ]),
        ];
    }

    private function local(?Carbon $time, ?string $timezone): ?string
    {
        return $time && $timezone ? $time->copy()->timezone($timezone)->format('Y-m-d H:i') : null;
    }
}
