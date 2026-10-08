<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActivityLog */
class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'event' => $this->event,
            'description' => $this->description,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name'])),
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'flight_id' => $this->flight_id,
            'passenger_id' => $this->passenger_id,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
