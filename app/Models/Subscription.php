<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

final class Subscription extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'customer_id', 'product_id', 'plan_id', 'invoice_id', 'subscription_number', 'status', 'starts_at', 'ends_at', 'trial_ends_at', 'grace_ends_at', 'auto_renew', 'price', 'currency', 'billing_cycle', 'metadata', 'renewal_notice_sent_at', 'created_by'];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['status' => SubscriptionStatus::class, 'billing_cycle' => BillingCycle::class, 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'trial_ends_at' => 'datetime', 'grace_ends_at' => 'datetime', 'auto_renew' => 'boolean', 'price' => 'decimal:2', 'metadata' => 'array', 'renewal_notice_sent_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class)->latest('effective_at');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionEntitlement::class);
    }

    public function license(): HasOne
    {
        return $this->hasOne(SoftwareLicense::class);
    }
}
