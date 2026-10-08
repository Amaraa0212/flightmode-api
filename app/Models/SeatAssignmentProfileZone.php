<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatAssignmentProfileZone extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SeatAssignmentProfile::class, 'seat_assignment_profile_id');
    }
}
