<?php

namespace App\Enums;

/** Passenger ban list (legacy passenger report "Ban Type"). Watch = warn only, everything else blocks check-in. */
enum BanType: string
{
    case NoFly = 'no_fly';
    case Unruly = 'unruly';
    case UnpaidDebt = 'unpaid_debt';
    case Watch = 'watch';

    public function blocksCheckin(): bool
    {
        return $this !== self::Watch;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
