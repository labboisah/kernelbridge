<?php

namespace App\Domain\PublicWebsite\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\ContactInquiry;
use Illuminate\Support\Facades\DB;

final readonly class CaptureContactInquiry
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes): ContactInquiry
    {
        return DB::transaction(function () use ($attributes): ContactInquiry {
            $inquiry = ContactInquiry::query()->create($attributes);
            $this->audit->record('public.contact_submitted', metadata: ['inquiry_id' => $inquiry->public_id]);

            return $inquiry;
        });
    }
}
