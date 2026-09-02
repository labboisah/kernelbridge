<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class DemoProfile extends Model
{
    protected $fillable = ['uuid', 'product_id', 'name', 'public_demo_url', 'is_active', 'requires_access_token', 'allow_create', 'allow_edit', 'allow_delete', 'allow_export', 'allow_email', 'allow_sms', 'allow_payment', 'allow_configuration', 'allow_user_management', 'protected_roles', 'protected_accounts', 'configuration', 'config_version', 'reset_enabled', 'reset_schedule', 'reset_instructions', 'reset_instruction_version', 'next_reset_at', 'last_reset_acknowledged_at', 'last_acknowledged_version'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'requires_access_token' => 'boolean', 'allow_create' => 'boolean', 'allow_edit' => 'boolean', 'allow_delete' => 'boolean', 'allow_export' => 'boolean', 'allow_email' => 'boolean', 'allow_sms' => 'boolean', 'allow_payment' => 'boolean', 'allow_configuration' => 'boolean', 'allow_user_management' => 'boolean', 'protected_roles' => 'array', 'protected_accounts' => 'array', 'configuration' => 'array', 'reset_enabled' => 'boolean', 'next_reset_at' => 'datetime', 'last_reset_acknowledged_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(DemoAccessToken::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DemoSession::class);
    }
}
