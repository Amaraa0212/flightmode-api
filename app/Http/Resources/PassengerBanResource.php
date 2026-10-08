<?php

namespace App\Http\Resources;

use App\Models\PassengerBan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PassengerBan */
class PassengerBanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline' => $this->whenLoaded('airline', fn () => $this->airline?->only(['id', 'name'])),
            'airline_id' => $this->airline_id,
            'ban_type' => $this->ban_type->value,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'document_number' => $this->document_number,
            'nationality' => $this->nationality,
            'reason' => $this->reason,
            'valid_until' => $this->valid_until?->toDateString(),
            'is_active' => $this->is_active,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
