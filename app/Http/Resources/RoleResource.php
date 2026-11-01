<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'scope' => $this->scope->value,
            'is_system' => $this->is_system,
            'airline_id' => $this->airline_id,
            'airline' => $this->whenLoaded('airline', fn () => $this->airline ? [
                'id' => $this->airline->id, 'name' => $this->airline->name, 'iata_code' => $this->airline->iata_code,
            ] : null),
            // airline users edit only their airline's roles, never the one they hold
            'editable' => $actor?->isSystemAdmin()
                || ($actor && $this->airline_id !== null && (int) $this->airline_id === (int) $actor->airline_id && (int) $this->id !== (int) $actor->role_id),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('key')->values()),
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
