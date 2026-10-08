<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Reusable online check-in settings that can be applied to many flights. */
class ServiceTemplate extends Model
{
    protected $guarded = ['id'];

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function seatAssignmentProfile(): BelongsTo
    {
        return $this->belongsTo(SeatAssignmentProfile::class);
    }

    public function baggageRules(): BelongsToMany
    {
        return $this->belongsToMany(BaggageRule::class);
    }

    public function seatPrices(): BelongsToMany
    {
        return $this->belongsToMany(SeatPrice::class);
    }
}
