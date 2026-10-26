<?php

namespace App\Models;

use App\Enums\CabinClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Seats of an agency allocation kept for passenger names entered later. */
class AgencyHold extends Model
{
    public const ACTIVE = 'active';

    public const RELEASED = 'released';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::ACTIVE];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'released_at' => 'datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(AgencyAllocation::class, 'agency_allocation_id');
    }

    public function fare(): BelongsTo
    {
        return $this->belongsTo(AgencyAllocationFare::class, 'agency_allocation_fare_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
