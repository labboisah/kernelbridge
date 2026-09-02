<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'product_id', 'name', 'slug', 'description', 'billing_cycle', 'price', 'currency', 'setup_fee', 'trial_days', 'grace_period_days', 'is_active', 'is_public', 'sort_order', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $plan) => $plan->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['billing_cycle' => BillingCycle::class, 'price' => 'decimal:2', 'setup_fee' => 'decimal:2', 'trial_days' => 'integer', 'grace_period_days' => 'integer', 'is_active' => 'boolean', 'is_public' => 'boolean', 'sort_order' => 'integer', 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_plan')->withTimestamps();
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class)->orderBy('sort_order');
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class)->orderBy('limit_name');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort_order');
    }
}
