<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
