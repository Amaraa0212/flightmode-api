<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Two fare bases that may be sold together on one itinerary (symmetric, stored once per pair). */
class FareCombination extends Model
{
    protected $guarded = ['id'];

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function fareClass(): BelongsTo
    {
        return $this->belongsTo(FareClass::class);
    }

    public function combinableFareClass(): BelongsTo
    {
        return $this->belongsTo(FareClass::class, 'combinable_fare_class_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The pair in either direction */
    public function scopePair(Builder $query, int $a, int $b): void
    {
        $query->where(fn ($q) => $q->where('fare_class_id', $a)->where('combinable_fare_class_id', $b))
            ->orWhere(fn ($q) => $q->where('fare_class_id', $b)->where('combinable_fare_class_id', $a));
    }

    public static function allowed(int $a, int $b): bool
    {
        return $a === $b || static::query()->where(fn ($q) => $q->pair($a, $b))->exists();
    }
}
