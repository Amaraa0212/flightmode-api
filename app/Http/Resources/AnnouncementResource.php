<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline' => $this->whenLoaded('airline', fn () => $this->airline?->only(['id', 'name'])),
            'agency' => $this->whenLoaded('agency', fn () => $this->agency?->only(['id', 'name'])),
            'airline_id' => $this->airline_id,
            'agency_id' => $this->agency_id,
            'title' => $this->title,
            'body' => $this->body,
            'requires_acknowledgement' => $this->requires_acknowledgement,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
