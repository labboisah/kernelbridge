<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerApplication extends Model
{
    protected $fillable = ['organisation', 'phone', 'location', 'website', 'details', 'background_knowledge', 'digital_skills', 'installation_experience', 'networking_experience', 'business_skills', 'management_skills', 'guided_answers'];

    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'guided_answers' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
