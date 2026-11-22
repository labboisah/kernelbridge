<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartnerPointEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'point_value' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(ProspectiveCustomer::class, 'prospective_customer_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class);
    }
}
