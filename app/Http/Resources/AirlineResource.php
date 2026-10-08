<?php

namespace App\Http\Resources;

use App\Models\Airline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Airline */
class AirlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iata_code' => $this->iata_code,
            'icao_code' => $this->icao_code,
            'numeric_code' => $this->numeric_code,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'registration_no' => $this->registration_no,
            'logo_path' => $this->logo_path,
            'brand_color' => $this->brand_color,
            'conditions_of_carriage' => $this->conditions_of_carriage,
            'bag_tag_layout' => $this->bag_tag_layout,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'agencies' => AgencyResource::collection($this->whenLoaded('agencies')),
        ];
    }
}
