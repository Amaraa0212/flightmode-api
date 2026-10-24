<?php

namespace App\Http\Resources;

use App\Models\FlightSeries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FlightSeries */
class FlightSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'aircraft_id' => $this->aircraft_id,
            'inventory_template_id' => $this->inventory_template_id,
            'flight_number' => $this->flight_number,
            'service_type' => $this->service_type ?? 'scheduled',
            'origin_airport_id' => $this->origin_airport_id,
            'destination_airport_id' => $this->destination_airport_id,
            'origin' => $this->whenLoaded('origin', fn () => $this->origin->iata_code),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination->iata_code),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'days_of_week' => $this->weekdays(),
            'departure_time' => substr((string) $this->departure_time, 0, 5),
            'arrival_time' => substr((string) $this->arrival_time, 0, 5),
            'arrival_day_offset' => $this->arrival_day_offset,
            'duration_minutes' => $this->duration_minutes,
            'gate' => $this->gate,
            'excess_baggage_max_kg' => $this->excess_baggage_max_kg,
            'excess_baggage_price_per_kg' => $this->excess_baggage_price_per_kg,
            'flights_count' => $this->whenCounted('flights'),
        ];
    }
}
