<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeadStageHistory extends Model
{
    protected $table = 'lead_stage_history';

    public $timestamps = false;

    protected $fillable = ['actor_id', 'status', 'entered_at'];

    protected function casts(): array
    {
        return ['status' => LeadStatus::class, 'entered_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
