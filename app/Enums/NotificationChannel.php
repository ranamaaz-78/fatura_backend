<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
