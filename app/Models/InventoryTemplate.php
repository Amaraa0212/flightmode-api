<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Saved cabin seats + RBD limits that can be applied to many flights. */
class InventoryTemplate extends Model
{
    protected $guarded = ['id'];

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cabins(): HasMany
    {
        return $this->hasMany(InventoryTemplateCabin::class);
    }

    public function rbds(): HasMany
    {
        return $this->hasMany(InventoryTemplateRbd::class);
    }
}
