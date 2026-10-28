<?php

namespace App\Http\Resources;

use App\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Agency */
class AgencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'owner_airline_id' => $this->owner_airline_id,
            'code' => $this->code,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'registration_no' => $this->registration_no,
            'api_enabled' => $this->api_enabled,
            'chinggis_sync' => (bool) $this->chinggis_sync,
            'is_active' => $this->is_active,
            'contract_active' => $this->whenPivotLoaded('agency_airline', fn () => (bool) $this->pivot->is_active),
            'airlines' => $this->whenLoaded('airlines', fn () => $this->airlines->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'contract_active' => (bool) $a->pivot->is_active,
            ])),
        ];
    }
}
