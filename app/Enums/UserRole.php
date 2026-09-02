<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Sales = 'sales';
    case Finance = 'finance';
    case Support = 'support';
    case Customer = 'customer';

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function administrative(): array
    {
        return [self::SuperAdmin->value, self::Admin->value, self::Sales->value, self::Finance->value, self::Support->value];
    }
}
