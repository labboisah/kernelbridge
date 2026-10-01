<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class HostingSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'monthly_price' => 'decimal:2'];
    }
}
