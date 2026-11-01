<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AirlineIpAddress extends Model
{
    protected $guarded = ['id'];

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }
}
