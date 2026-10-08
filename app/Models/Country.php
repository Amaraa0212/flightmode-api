<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $guarded = ['id'];

    public function airports(): HasMany
    {
        return $this->hasMany(Airport::class);
    }
}
