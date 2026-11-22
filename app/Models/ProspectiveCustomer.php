<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ProspectiveCustomer extends Model
{
    protected $fillable = [
        'name', 'organization', 'email', 'phone', 'location', 'organization_type',
        'number_of_users', 'product_id', 'follow_up_date', 'initial_notes',
    ];

    protected static function booted(): void
    {
        self::creating(fn (self $prospect) => $prospect->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
            'contacted_at' => 'datetime',
            'interested_at' => 'datetime',
            'consent_at' => 'datetime',
            'number_of_users' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function partnerOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function convertedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'converted_lead_id');
    }
}
