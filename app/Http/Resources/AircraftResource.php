<?php

namespace App\Http\Resources;

use App\Models\Aircraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Aircraft */
class AircraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'airline' => $this->whenLoaded('airline', fn () => ['id' => $this->airline->id, 'name' => $this->airline->name]),
            'registration' => $this->registration,
            'type' => $this->type,
            'seat_configuration' => $this->seat_configuration,
            'total_seats' => $this->total_seats,
            'max_infants' => $this->max_infants,
            'is_active' => $this->is_active,
            'cabins' => $this->whenLoaded('cabins', fn () => $this->cabinSeatCounts()),
            'seats_count' => $this->whenCounted('seats'),
            'load_zones' => $this->whenLoaded('loadZones', fn () => $this->loadZones->map->only(['id', 'zone', 'pax_from', 'pax_to'])),
        ];
    }
}
