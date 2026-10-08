<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\PassengerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Passenger extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => PassengerType::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'document_issued_at' => 'date',
            'document_expires_at' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function infantOf(): BelongsTo
    {
        return $this->belongsTo(Passenger::class, 'infant_of_passenger_id');
    }

    public function infant(): HasOne
    {
        return $this->hasOne(Passenger::class, 'infant_of_passenger_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(PassengerSegment::class)->orderBy('segment_no');
    }

    public function fullName(): string
    {
        return trim($this->last_name.'/'.$this->first_name.($this->middle_name ? ' '.$this->middle_name : ''));
    }

    public function isInfant(): bool
    {
        return $this->type === PassengerType::Infant;
    }
}
