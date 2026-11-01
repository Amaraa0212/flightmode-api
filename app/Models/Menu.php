<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A back-office menu entry; `key` is the prefix of the permissions it owns (flights → flights.view ...). */
class Menu extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'is_active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class, 'menu_group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class)->orderBy('order');
    }

    /** Permission needed to see the entry: its own, else {key}.view */
    public function visibilityKey(): ?string
    {
        return $this->permission_key ?: ($this->key ? $this->key.'.view' : null);
    }

    public function allowsScope(string $scope): bool
    {
        return empty($this->scopes) || in_array($scope, $this->scopes, true);
    }
}
