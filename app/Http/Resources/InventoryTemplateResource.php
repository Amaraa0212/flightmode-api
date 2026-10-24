<?php

namespace App\Http\Resources;

use App\Models\InventoryTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryTemplate */
class InventoryTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'aircraft_id' => $this->aircraft_id,
            'aircraft' => $this->whenLoaded('aircraft', fn () => $this->aircraft ? [
                'id' => $this->aircraft->id, 'registration' => $this->aircraft->registration, 'type' => $this->aircraft->type,
            ] : null),
            'name' => $this->name,
            'description' => $this->description,
            'cabins' => $this->whenLoaded('cabins', fn () => $this->cabins->map(fn ($c) => [
                'cabin_class' => $c->cabin_class->value, 'seats' => $c->seats, 'oversell' => $c->oversell, 'curtail' => $c->curtail,
            ])->values()),
            'rbds' => $this->whenLoaded('rbds', fn () => $this->rbds->map(fn ($r) => [
                'rbd_id' => $r->rbd_id,
                'code' => $r->relationLoaded('rbd') ? $r->rbd?->code : null,
                'cabin_class' => $r->relationLoaded('rbd') ? $r->rbd?->cabin_class->value : null,
                'seats' => $r->seats,
                'is_closed' => $r->is_closed,
            ])->values()),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
