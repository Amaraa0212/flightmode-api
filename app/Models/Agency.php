<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agency extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'api_enabled' => 'boolean'];
    }

    public function airlines(): BelongsToMany
    {
        return $this->belongsToMany(Airline::class)->withPivot('is_active')->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AgencyAllocation::class);
    }
}
