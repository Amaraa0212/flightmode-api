<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fare family (brand): booking classes sold under one name, e.g. BASIC / STANDARD / FLEX. */
class FareFamily extends Model
{
    protected $guarded = ['id'];

    /** Column defaults, so a freshly created model returns them too */
    protected $attributes = ['is_active' => true, 'rank' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rbds(): HasMany
    {
        return $this->hasMany(Rbd::class);
    }
}
