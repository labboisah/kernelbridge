<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Lead extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'lead_number', 'name', 'organization', 'email', 'phone', 'location', 'organization_type', 'product_id', 'number_of_users', 'source', 'status', 'assigned_to', 'follow_up_date', 'converted_customer_id', 'converted_at', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $lead) => $lead->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['status' => LeadStatus::class, 'follow_up_date' => 'date', 'converted_at' => 'datetime', 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function demoRequests(): HasMany
    {
        return $this->hasMany(DemoRequest::class)->latest();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $query->when($search, fn (Builder $query, string $term) => $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")->orWhere('organization', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('lead_number', 'like', "%{$term}%");
        }));
    }
}
