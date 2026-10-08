<?php

namespace App\Http\Resources;

use App\Models\SeatAssignmentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SeatAssignmentProfile */
class SeatAssignmentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'aircraft_id' => $this->aircraft_id,
            'name' => $this->name,
            'zones' => $this->whenLoaded('zones', fn () => $this->orderedZones()),
        ];
    }
}
