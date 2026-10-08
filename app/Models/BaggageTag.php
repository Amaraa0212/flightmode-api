<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaggageTag extends Model
{
    protected $guarded = ['id'];

    public function checkedBaggage(): BelongsTo
    {
        return $this->belongsTo(CheckedBaggage::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
