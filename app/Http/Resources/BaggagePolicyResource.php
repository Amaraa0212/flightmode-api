<?php

namespace App\Http\Resources;

use App\Models\BaggagePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BaggagePolicy */
class BaggagePolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'type' => $this->type->value,
            'locale' => $this->locale,
            'title' => $this->title,
            'body' => $this->body,
            'is_active' => $this->is_active,
        ];
    }
}
