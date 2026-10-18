<?php

namespace App\Http\Resources;

use App\Models\IssueReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IssueReport */
class IssueReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name', 'email'])),
            'airline' => $this->whenLoaded('airline', fn () => $this->airline?->only(['id', 'name'])),
            'agency' => $this->whenLoaded('agency', fn () => $this->agency?->only(['id', 'name'])),
            'user_id' => $this->user_id,
            'airline_id' => $this->airline_id,
            'agency_id' => $this->agency_id,
            'page_path' => $this->page_path,
            'page_title' => $this->page_title,
            'message' => $this->message,
            'user_agent' => $this->user_agent,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
