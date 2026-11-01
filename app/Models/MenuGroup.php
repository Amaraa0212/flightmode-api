<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuGroup extends Model
{
    protected $guarded = ['id'];

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class)->orderBy('order');
    }
}
