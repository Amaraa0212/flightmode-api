<?php

namespace App\Http\Resources;

use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Airport */
class AirportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'iata_code' => $this->iata_code,
            'icao_code' => $this->icao_code,
            'name' => $this->name,
            'city' => $this->city,
            'city_code' => $this->city_code,
            'country' => $this->whenLoaded('country', fn () => $this->country ? ['id' => $this->country->id, 'name' => $this->country->name, 'alpha2' => $this->country->alpha2] : null),
            'country_id' => $this->country_id,
            'timezone' => $this->timezone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => $this->is_active,
        ];
    }
}
