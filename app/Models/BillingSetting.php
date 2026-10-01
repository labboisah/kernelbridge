<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingSetting extends Model
{
    protected $fillable = ['vat_rate'];

    protected function casts(): array
    {
        return ['vat_rate' => 'decimal:2'];
    }

    public static function vatRate(): string
    {
        return static::findOrFail(1)->vat_rate;
    }
}
