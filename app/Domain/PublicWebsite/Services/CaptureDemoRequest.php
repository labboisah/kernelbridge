<?php

namespace App\Domain\PublicWebsite\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Models\DemoRequest;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Notifications\ProspectDemoConfirmation;
use App\Notifications\SalesNewDemoRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final readonly class CaptureDemoRequest
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes): DemoRequest
    {
        $request = DB::transaction(function () use ($attributes): DemoRequest {
            $publicId = $attributes['product_id'] ?? null;
            $productId = $publicId ? Product::query()->where('public_id', $publicId)->value('id') : null;
            $lead = Lead::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($attributes['email'])])->first();
            if (! $lead) {
                $lead = Lead::query()->create([
                    'name' => $attributes['name'], 'organization' => $attributes['organization'], 'email' => mb_strtolower($attributes['email']),
                    'phone' => $attributes['phone'] ?? null, 'location' => $attributes['location'] ?? null, 'organization_type' => $attributes['organization_type'],
                    'product_id' => $productId, 'number_of_users' => $attributes['number_of_users'] ?? null, 'source' => $attributes['source'] ?? 'Website', 'status' => 'new',
                ]);
                $lead->forceFill(['lead_number' => sprintf('KBT-LEAD-%06d', $lead->id)])->save();
            }

            $request = DemoRequest::query()->create([
                ...$attributes, 'lead_id' => $lead->id, 'product_id' => $productId, 'company' => $attributes['organization'],
                'team_size' => isset($attributes['number_of_users']) ? (string) $attributes['number_of_users'] : null, 'status' => 'pending',
            ]);
            $lead->activities()->create(['type' => 'demo_requested', 'description' => 'Public demo request submitted.', 'metadata' => ['demo_request_uuid' => $request->uuid, 'product_id' => $productId]]);
            $this->audit->record('public.demo_requested', metadata: ['request_id' => $request->public_id, 'lead_uuid' => $lead->uuid, 'product_id' => $publicId]);

            return $request;
        });

        $request->load(['lead', 'product']);
        $salesStaff = User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', [UserRole::Sales->value, UserRole::Admin->value, UserRole::SuperAdmin->value]))->get();
        Notification::send($salesStaff, new SalesNewDemoRequest($request));
        Notification::route('mail', $request->email)->notify(new ProspectDemoConfirmation($request));

        return $request;
    }
}
