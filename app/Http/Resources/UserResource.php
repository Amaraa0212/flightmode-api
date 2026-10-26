<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'role' => $this->whenLoaded('role', fn () => ['id' => $this->role->id, 'name' => $this->role->name, 'slug' => $this->role->slug, 'scope' => $this->role->scope->value]),
            'airline' => $this->whenLoaded('airline', fn () => $this->airline ? ['id' => $this->airline->id, 'name' => $this->airline->name] : null),
            'agency' => $this->whenLoaded('agency', fn () => $this->agency ? ['id' => $this->agency->id, 'name' => $this->agency->name] : null),
            'airport' => $this->whenLoaded('airport', fn () => $this->airport ? [
                'id' => $this->airport->id,
                'iata_code' => $this->airport->iata_code,
                'name' => $this->airport->name,
                'city' => $this->airport->city,
                'timezone' => $this->airport->timezone,
            ] : null),
            'two_factor_enabled' => $this->hasTwoFactor(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
