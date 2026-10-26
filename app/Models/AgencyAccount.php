<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Prepaid balance of an agency with one airline. Agencies without one sell without a balance check. */
class AgencyAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AgencyAccountTransaction::class);
    }
}
