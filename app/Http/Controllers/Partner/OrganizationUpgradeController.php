<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Security\Contracts\FileScanner;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\PartnerOrganizationUpgrade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

final class OrganizationUpgradeController extends Controller
{
    private function partnerApplication(Request $request): PartnerApplication
    {
        abort_unless($request->user()->isApprovedAgent(), 403, 'An approved sales-agent application is required.');

        $application = PartnerApplication::query()
            ->where('user_id', $request->user()->id)
            ->where('type', 'sales_agent')
            ->where('status', 'approved')
            ->firstOrFail();

        abort_unless($application->partner_category === 'individual', 404, 'This partner account is already an organization account.');

        return $application;
    }

    public function show(Request $request)
    {
        $application = $this->partnerApplication($request);
        $upgrade = PartnerOrganizationUpgrade::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        return Inertia::render('Partner/OrganizationUpgrade', [
            'upgrade' => $upgrade,
            'readOnly' => $upgrade?->status === 'submitted',
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, FileScanner $scanner)
    {
        $application = $this->partnerApplication($request);
        $data = $request->validate([
            'business_legal_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'max:100'],
            'registration_country' => ['required', 'string', 'max:100'],
            'business_type' => ['required', 'in:private_limited,public_limited,partnership,cooperative,nonprofit,sole_proprietorship,other'],
            'incorporation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url:http,https', 'max:500'],
            'business_email' => ['required', 'email', 'max:255'],
            'business_phone' => ['required', 'string', 'max:40'],
            'registered_address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:100'],
            'state_region' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'representative_name' => ['required', 'string', 'max:255'],
            'representative_title' => ['required', 'string', 'max:150'],
            'representative_email' => ['required', 'email', 'max:255'],
            'representative_phone' => ['required', 'string', 'max:40'],
            'ownership_details' => ['required', 'array', 'min:1', 'max:20'],
            'ownership_details.*.full_name' => ['required', 'string', 'max:255'],
            'ownership_details.*.role' => ['required', 'string', 'max:150'],
            'ownership_details.*.ownership_percentage' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:100'],
            'ownership_details.*.country' => ['nullable', 'string', 'max:100'],
            'business_activity' => ['required', 'string', 'max:3000'],
            'partnership_goals' => ['required', 'string', 'max:3000'],
            'registration_proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'owner_identity' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'address_proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'office_photo_one' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'office_photo_two' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $ownershipBasisPoints = array_sum(array_map(
            fn (array $owner): int => (int) round((float) $owner['ownership_percentage'] * 100),
            $data['ownership_details'],
        ));
        if ($ownershipBasisPoints !== 10000) {
            throw ValidationException::withMessages([
                'ownership_details' => 'Ownership percentages must add up to exactly 100%.',
            ]);
        }

        $documents = [
            'registration_proof' => 'registration_proof_path',
            'owner_identity' => 'owner_identity_path',
            'address_proof' => 'address_proof_path',
            'office_photo_one' => 'office_photo_one_path',
            'office_photo_two' => 'office_photo_two_path',
        ];
        foreach (array_keys($documents) as $field) {
            $scanner->assertClean($request->file($field));
        }
        $stored = [];
        try {
            DB::transaction(function () use ($request, $application, $data, $documents, &$stored): void {
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $lockedApplication = PartnerApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
                if ($lockedApplication->partner_category !== 'individual' || $lockedApplication->status !== 'approved') {
                    throw ValidationException::withMessages(['business_legal_name' => 'Only approved individual sales partners can request this upgrade.']);
                }
                if (PartnerOrganizationUpgrade::query()->where('user_id', $request->user()->id)->where('status', 'submitted')->exists()) {
                    throw ValidationException::withMessages(['business_legal_name' => 'An organization upgrade request is already awaiting review.']);
                }

                foreach ($documents as $field => $column) {
                    $path = $request->file($field)->store('partner-organization-documents', 'local');
                    if ($path === false) {
                        throw new RuntimeException('Unable to store an organization upgrade document.');
                    }
                    $stored[$column] = $path;
                }
                PartnerOrganizationUpgrade::query()->create([
                    ...collect($data)->except(array_keys($documents))->all(),
                    ...$stored,
                    'user_id' => $request->user()->id,
                    'partner_application_id' => $lockedApplication->id,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($stored as $path) {
                if (! Storage::disk('local')->delete($path)) {
                    report(new RuntimeException('Unable to clean up an unused organization upgrade document.'));
                }
            }
            throw $exception;
        }

        return back()->with('success', 'Organization upgrade request submitted for review.');
    }
}
