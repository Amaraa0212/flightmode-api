<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrepaidBaggage extends Model
{
    protected $table = 'prepaid_baggage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:1'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(PassengerSegment::class, 'passenger_segment_id');
    }
}
