<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerApplication extends Model
{
    protected $fillable = ['organisation', 'phone', 'location', 'website', 'details'];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
