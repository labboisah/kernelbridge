<?php

namespace App\Models;

use App\Enums\DemoRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DemoRequest extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'public_id', 'lead_id', 'product_id', 'name', 'organization', 'email', 'phone', 'location', 'organization_type', 'number_of_users', 'preferred_demo_date', 'preferred_demo_time', 'scheduled_at', 'company', 'job_title', 'team_size', 'message', 'source', 'assigned_to', 'status'];

    protected static function booted(): void
    {
        self::creating(function (self $request): void {
            $request->uuid ??= (string) str()->uuid();
            $request->public_id ??= (string) str()->ulid();
        });
    }

    protected function casts(): array
    {
        return ['status' => DemoRequestStatus::class, 'preferred_demo_date' => 'date', 'scheduled_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
