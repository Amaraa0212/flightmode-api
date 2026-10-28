<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agency extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'api_enabled' => 'boolean', 'chinggis_sync' => 'boolean'];
    }

    /** Sales channels an agency can be (own channels of an airline are usually not travel agencies) */
    public const CHANNELS = ['agency', 'ticket_office', 'web', 'call_center'];

    /** The airline this agency is a sales channel of; null = a global agency */
    public function ownerAirline(): BelongsTo
    {
        return $this->belongsTo(Airline::class, 'owner_airline_id');
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
