<?php

namespace App\Enums;

enum Gender: string
{
    case Male = 'M';
    case Female = 'F';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
