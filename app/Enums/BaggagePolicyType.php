<?php

namespace App\Enums;

enum BaggagePolicyType: string
{
    case Cabin = 'cabin';
    case Checked = 'checked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
