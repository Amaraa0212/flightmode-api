<?php

namespace App\Models;

use App\Enums\CabinClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Reservation booking designator: a booking class letter inside a cabin. */
class Rbd extends Model
{
    protected $guarded = ['id'];

    /** Column defaults, so a freshly created model returns them too */
    protected $attributes = ['is_active' => true, 'is_upgrade' => false];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'is_active' => 'boolean',
            'is_upgrade' => 'boolean',
            'advance_booking_days' => 'integer',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function fareFamily(): BelongsTo
    {
        return $this->belongsTo(FareFamily::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fareClasses(): HasMany
    {
        return $this->hasMany(FareClass::class);
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(RbdModification::class);
    }
}
