<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_acknowledgement' => 'boolean'];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_dismissals')->withPivot('dismissed_at');
    }

    /**
     * Announcements a user should see:
     *  - agency users: addressed to their agency, or to all agencies of an airline they are contracted with
     *  - airline / airport users: addressed to their airline (not agency specific)
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isSystemAdmin()) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            if ($user->agency_id) {
                $airlineIds = $user->agency?->airlines()->pluck('airlines.id')->all() ?? [];
                $q->where('agency_id', $user->agency_id)
                    ->orWhere(fn (Builder $x) => $x->whereNull('agency_id')->whereIn('airline_id', $airlineIds));
            }

            if ($user->airline_id) {
                $q->orWhere(fn (Builder $x) => $x->where('airline_id', $user->airline_id)->whereNull('agency_id'));
            }

            // Station staff (DCS / gate) without an airline: airline-wide announcements (not agency-specific)
            if (! $user->agency_id && ! $user->airline_id && $user->airport_id) {
                $q->whereNull('agency_id');
            }

            if (! $user->agency_id && ! $user->airline_id && ! $user->airport_id) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
