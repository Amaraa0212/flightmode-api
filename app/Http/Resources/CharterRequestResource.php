<?php

namespace App\Http\Resources;

use App\Models\CharterRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CharterRequest */
class CharterRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester->only(['id', 'name', 'email'])),
            'origin' => $this->whenLoaded('origin', fn () => $this->origin->iata_code),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination->iata_code),
            'origin_airport_id' => $this->origin_airport_id,
            'destination_airport_id' => $this->destination_airport_id,
            'departure_date' => $this->departure_date->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'pax_count' => $this->pax_count,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'airlines' => $this->whenLoaded('airlines', fn () => $this->airlines->map->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
