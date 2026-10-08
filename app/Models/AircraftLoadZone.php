<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Zone `zone` is opened for seating while the passenger count is between pax_from and pax_to. */
class AircraftLoadZone extends Model
{
    protected $guarded = ['id'];

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }
}
