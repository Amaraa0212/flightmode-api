<?php

namespace App\Models;

use App\Enums\PassengerType;
use App\Enums\TripType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FareClass extends Model
{
    public const STATUSES = ['HK', 'SA'];

    protected $guarded = ['id'];

    /** Column defaults, so a freshly created model returns them too */
    protected $attributes = ['status' => 'HK', 'waitlist' => false, 'is_visible' => true, 'is_group' => false, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'trip_type' => TripType::class,
            'waitlist' => 'boolean',
            'is_visible' => 'boolean',
            'is_group' => 'boolean',
            'is_active' => 'boolean',
            'ticket_time_limit_hours' => 'integer',
            'ticket_time_limit_before_departure_hours' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(FareDiscount::class);
    }

    public function fares(): HasMany
    {
        return $this->hasMany(Fare::class);
    }

    /** Discount % for a passenger type (0 when none is set). */
    public function discountFor(PassengerType $type): int
    {
        return (int) ($this->discounts->firstWhere('passenger_type', $type)?->percentage ?? 0);
    }
}
