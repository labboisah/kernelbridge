<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\PartnerBankDetails;
use App\Models\PartnerOrganizationUpgrade;
use App\Notifications\PartnerApplicationApproved;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PartnerController extends Controller
{
    public function index()
    {
        $applications = PartnerApplication::with('user:id,name,email')->where('status', '!=', 'draft')->latest('submitted_at')->paginate(20);
        $bankDetails = PartnerBankDetails::whereIn('user_id', $applications->pluck('user_id'))->get()->keyBy('user_id');
        $applications->getCollection()->each(function (PartnerApplication $application) use ($bankDetails): void {
            $bank = $bankDetails->get($application->user_id);
            $application->setAttribute('bank_details', $bank ? [
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'account_number' => $bank->account_number,
            ] : null);
            $application->setAttribute('has_photo', $application->photo_path !== null);
        });

        $organizationUpgrades = PartnerOrganizationUpgrade::query()
            ->with(['partner:id,name,email', 'reviewer:id,name'])
            ->latest('submitted_at')
            ->paginate(10, ['*'], 'upgrade_page');
        $organizationUpgrades->getCollection()->each(fn (PartnerOrganizationUpgrade $upgrade) => $upgrade->setAttribute('documents', $upgrade->documentAvailability()));

        return Inertia::render('Admin/Partners/Index', [
            'applications' => $applications,
            'organizationUpgrades' => $organizationUpgrades,
            'types' => config('partners'),
            'questionnaire' => config('partner_questionnaire'),
        ])
            ->toResponse(request())->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, PartnerApplication $application)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'declined'])], 'review_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($application, $request, $data): void {
            $changed = PartnerApplication::whereKey($application->id)->where('status', 'submitted')->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            abort_unless($changed, 409, 'Only submitted applications can be reviewed.');

            if ($data['status'] === 'approved') {
                $application->user()->firstOrFail()->notify(new PartnerApplicationApproved($application->type));
            }
        });

        return back()->with('success', 'Partner application reviewed.');
    }

    public function reviewOrganizationUpgrade(Request $request, PartnerOrganizationUpgrade $upgrade)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'declined'])],
            'review_note' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($upgrade, $request, $data): void {
            $upgrade = PartnerOrganizationUpgrade::query()->whereKey($upgrade->id)->lockForUpdate()->firstOrFail();
            abort_unless($upgrade->status === 'submitted', 409, 'Only submitted organization upgrades can be reviewed.');

            $partner = $upgrade->partnerApplication()->lockForUpdate()->firstOrFail();
            abort_unless($partner->type === 'sales_agent' && $partner->status === 'approved', 409, 'The related sales-agent application is no longer approved.');
            abort_unless($partner->partner_category === 'individual', 409, 'This partner is already an organization partner.');

            $upgrade->forceFill([
                ...$data,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
            if ($data['status'] === 'approved') {
                $partner->forceFill(['partner_category' => 'organization'])->save();
            }
        });

        return back()->with('success', 'Organization upgrade request reviewed.');
    }

    public function downloadOrganizationDocument(Request $request, PartnerOrganizationUpgrade $upgrade, string $document, AuditRecorder $audit)
    {
        $column = match ($document) {
            'registration' => 'registration_proof_path',
            'identity' => 'owner_identity_path',
            'address' => 'address_proof_path',
            'office_photo_one' => 'office_photo_one_path',
            'office_photo_two' => 'office_photo_two_path',
            default => abort(404),
        };
        $path = $upgrade->getAttribute($column);
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $audit->record('partner.organization_document_downloaded', $request->user(), [
            'upgrade_id' => $upgrade->id,
            'document' => $document,
        ], $upgrade);

        return Storage::disk('local')->download($path, basename($path), [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
