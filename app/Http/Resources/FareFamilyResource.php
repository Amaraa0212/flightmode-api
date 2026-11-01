<?php

namespace App\Http\Resources;

use App\Models\FareFamily;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FareFamily */
class FareFamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'rank' => $this->rank,
            'is_active' => $this->is_active,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'rbds' => $this->whenLoaded('rbds', fn () => $this->rbds->pluck('code')->sort()->values()),
            'rbds_count' => $this->whenCounted('rbds'),
        ];
    }
}
