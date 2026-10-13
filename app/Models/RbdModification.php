<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Change / refund / no-show penalty of an RBD. */
class RbdModification extends Model
{
    public const TYPES = ['REFUND', 'NAME_CHANGE', 'DATE_CHANGE', 'ROUTE_CHANGE', 'CANCEL', 'NOSHOW'];

    protected $guarded = ['id'];

    /** Column defaults, so a freshly created model returns them too */
    protected $attributes = ['timeframe_hours' => 0, 'is_allowed' => true];

    protected function casts(): array
    {
        return [
            'timeframe_hours' => 'integer',
            'amount' => 'decimal:2',
            'percentage' => 'integer',
            'is_allowed' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function rbd(): BelongsTo
    {
        return $this->belongsTo(Rbd::class);
    }
}
