<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'public_id',
        'email',
        'password',
        'status',
        'email_verified_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->public_id ??= (string) str()->ulid();
        });
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isPartner(): bool
    {
        return PartnerApplication::where('user_id', $this->id)->whereIn('type', array_keys(config('partners')))->exists();
    }

    public function isApprovedAgent(): bool
    {
        return $this->hasRole(\App\Enums\UserRole::Agent->value)
            && PartnerApplication::where('user_id', $this->id)->where('type', 'sales_agent')->where('status', 'approved')->exists();
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class)->withPivot('is_primary')->withTimestamps();
    }

    public function supportTeams(): BelongsToMany
    {
        return $this->belongsToMany(SupportTeam::class)->withTimestamps();
    }
}
