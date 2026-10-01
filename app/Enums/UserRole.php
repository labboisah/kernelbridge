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
    case Partner = 'partner';
    case Investor = 'investor';
    case Agent = 'agent';
    case Developer = 'developer';

    public static function forPartnerType(string $type): string
    {
        return match ($type) {
            'sales_agent' => self::Agent->value,
            'developer' => self::Developer->value,
            'investor' => self::Investor->value,
        };
    }

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
