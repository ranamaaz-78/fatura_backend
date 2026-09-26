<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case BusinessAdmin = 'business_admin';
    case Staff = 'staff';

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
