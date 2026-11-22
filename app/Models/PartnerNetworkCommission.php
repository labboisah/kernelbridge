<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartnerNetworkCommission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level_weight' => 'decimal:2',
            'pool_rate' => 'decimal:2',
            'pool_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'earned_at' => 'datetime',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function sourcePartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_partner_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
