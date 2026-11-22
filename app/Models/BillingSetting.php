<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingSetting extends Model
{
    protected $fillable = [
        'vat_rate',
        'partner_point_value',
        'partner_network_sharing_enabled',
        'partner_network_min_active_clients',
        'partner_network_pool_rate',
        'partner_network_qualified_weight',
        'partner_network_premium_weight',
        'partner_network_elite_weight',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'partner_point_value' => 'decimal:2',
            'partner_network_sharing_enabled' => 'boolean',
            'partner_network_min_active_clients' => 'integer',
            'partner_network_pool_rate' => 'decimal:2',
            'partner_network_qualified_weight' => 'decimal:2',
            'partner_network_premium_weight' => 'decimal:2',
            'partner_network_elite_weight' => 'decimal:2',
        ];
    }

    public static function vatRate(): string
    {
        return static::findOrFail(1)->vat_rate;
    }
}
