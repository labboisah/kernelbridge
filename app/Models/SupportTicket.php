<?php

namespace App\Models;

use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'ticket_number', 'customer_id', 'product_id', 'support_team_id', 'subject', 'category', 'priority', 'status', 'assigned_to', 'opened_by', 'opened_at', 'closed_at', 'first_response_due_at', 'resolution_due_at', 'first_responded_at', 'resolved_at', 'sla_breached_at', 'last_customer_reply_at', 'last_staff_reply_at'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['priority' => SupportPriority::class, 'status' => SupportStatus::class, 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_responded_at' => 'datetime', 'resolved_at' => 'datetime', 'sla_breached_at' => 'datetime', 'last_customer_reply_at' => 'datetime', 'last_staff_reply_at' => 'datetime'];
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class, 'support_team_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->oldest();
    }
}
