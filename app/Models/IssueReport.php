<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A problem a user reported from the help panel of a page. */
class IssueReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    /** Statuses that close a report (resolved_by / resolved_at are set). */
    public const CLOSED_STATUSES = [self::STATUS_RESOLVED, self::STATUS_REJECTED];

    protected $guarded = ['id'];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_REJECTED];
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
