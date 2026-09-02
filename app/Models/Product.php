<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Product extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'public_id', 'code', 'name', 'slug', 'tagline', 'hero_description', 'short_description', 'description', 'category', 'seo_title', 'seo_description', 'industry', 'icon', 'logo', 'cover_image', 'features', 'benefits', 'workflow', 'platform_components', 'screenshots', 'plans', 'demo_url', 'website_url', 'is_featured', 'metadata', 'sort_order', 'status'];

    protected static function booted(): void
    {
        self::creating(function (self $product): void {
            $product->uuid ??= (string) Str::uuid();
            $product->public_id ??= (string) str()->ulid();
        });
    }

    protected function casts(): array
    {
        return ['features' => 'array', 'benefits' => 'array', 'workflow' => 'array', 'platform_components' => 'array', 'screenshots' => 'array', 'plans' => 'array', 'metadata' => 'array', 'is_featured' => 'boolean', 'status' => ProductStatus::class];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value)->orderBy('sort_order');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->published()->where('is_featured', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function productFeatures(): HasMany
    {
        return $this->hasMany(ProductFeature::class)->orderBy('sort_order');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProductLink::class)->orderBy('sort_order');
    }

    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('sort_order');
    }

    public function associatedPlans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'product_plan')->withTimestamps();
    }
}
