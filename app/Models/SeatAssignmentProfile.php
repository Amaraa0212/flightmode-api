<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Order in which zones are filled when a seat is assigned automatically. */
class SeatAssignmentProfile extends Model
{
    protected $guarded = ['id'];

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(SeatAssignmentProfileZone::class)->orderBy('priority');
    }

    /** @return array<string> zones in fill order */
    public function orderedZones(): array
    {
        return $this->zones->pluck('zone')->all();
    }
}
