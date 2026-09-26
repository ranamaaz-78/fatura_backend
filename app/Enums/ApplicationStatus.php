<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
