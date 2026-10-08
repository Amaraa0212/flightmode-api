<?php

namespace App\Enums;

enum CharterRequestStatus: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
