<?php

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Note = 'note';
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Converted = 'converted';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
