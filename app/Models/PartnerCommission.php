<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerCommission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'base_amount' => 'decimal:2', 'amount' => 'decimal:2', 'earned_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
