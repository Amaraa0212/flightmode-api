<?php

namespace App\Models;

use App\Enums\RoleScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scope' => RoleScope::class, 'is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** Set when the airline created the role for its own staff */
    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
