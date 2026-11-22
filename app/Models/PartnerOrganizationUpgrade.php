<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PartnerOrganizationUpgrade extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['registration_proof_path', 'owner_identity_path', 'address_proof_path', 'office_photo_one_path', 'office_photo_two_path'];

    public function documentAvailability(): array
    {
        return [
            'registration' => $this->registration_proof_path !== null,
            'identity' => $this->owner_identity_path !== null,
            'address' => $this->address_proof_path !== null,
            'office_photo_one' => $this->office_photo_one_path !== null,
            'office_photo_two' => $this->office_photo_two_path !== null,
        ];
    }

    protected function casts(): array
    {
        return [
            'incorporation_date' => 'date',
            'ownership_details' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function partnerApplication(): BelongsTo
    {
        return $this->belongsTo(PartnerApplication::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
