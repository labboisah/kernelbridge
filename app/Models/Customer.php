<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'customer_number', 'type', 'name', 'email', 'phone', 'website', 'tax_id', 'billing_address', 'city', 'state', 'country', 'status', 'source', 'assigned_to', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $customer) => $customer->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['type' => CustomerType::class, 'status' => CustomerStatus::class, 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_primary');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest();
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->latest();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->latest();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('payment_date');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(SoftwareLicense::class)->latest();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'customer_product')->withTimestamps()->orderBy('sort_order');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_primary')->withTimestamps();
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class)->latest('opened_at');
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $query->when($search, fn (Builder $query, string $term) => $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('customer_number', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        }));
    }
}
