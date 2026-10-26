<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyAccountTransaction extends Model
{
    public const TOPUP = 'topup';

    public const TICKET = 'ticket';

    public const REFUND = 'refund';

    public const ADJUSTMENT = 'adjustment';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AgencyAccount::class, 'agency_account_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class)->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
