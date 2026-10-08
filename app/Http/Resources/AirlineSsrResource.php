<?php

namespace App\Http\Resources;

use App\Models\AirlineSsr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AirlineSsr */
class AirlineSsrResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'ssr' => new SsrCodeResource($this->whenLoaded('ssrCode')),
            'price' => $this->price,
            'currency' => $this->currency,
        ];
    }
}
