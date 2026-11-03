<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Airline extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'ip_restriction_enabled' => 'boolean'];
    }

    public function agencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class)->withPivot(['is_active', 'can_import_passengers', 'can_assign_seats'])->withTimestamps();
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    /** Addresses its back-office staff may sign in from (when ip_restriction_enabled) */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(AirlineIpAddress::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function ssrs(): HasMany
    {
        return $this->hasMany(AirlineSsr::class);
    }

    /** Public URL of the logo (printed on boarding passes and bag tags), or null */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return str_starts_with($this->logo_path, 'http') ? $this->logo_path : Storage::disk('public')->url($this->logo_path);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
