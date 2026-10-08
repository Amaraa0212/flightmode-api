<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightSeatBlock extends Model
{
    protected $guarded = ['id'];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
