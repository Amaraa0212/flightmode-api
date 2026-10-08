<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Passengers checked in together (shared baggage allowance). */
class CheckinGroup extends Model
{
    protected $guarded = ['id'];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(PassengerSegment::class);
    }
}
