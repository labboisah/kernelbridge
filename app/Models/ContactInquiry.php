<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;

final class ContactInquiry extends Model
{
    protected $fillable = ['public_id', 'name', 'email', 'phone', 'company', 'subject', 'message', 'status'];

    protected static function booted(): void
    {
        self::creating(fn (self $inquiry) => $inquiry->public_id ??= (string) str()->ulid());
    }

    protected function casts(): array
    {
        return ['status' => InquiryStatus::class];
    }
}
