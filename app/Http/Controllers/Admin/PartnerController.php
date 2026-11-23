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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class PartnerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'declined'])],
        ]);
        $applications = PartnerApplication::with('user:id,name,email')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = $filters['search'];
                $query->where(fn ($query) => $query->where('organisation', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')));
            })
            ->latest('created_at')->latest('id')->paginate(20)->withQueryString();
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
            ->paginate(10, ['*'], 'upgrade_page')->withQueryString();
        $organizationUpgrades->getCollection()->each(fn (PartnerOrganizationUpgrade $upgrade) => $upgrade->setAttribute('documents', $upgrade->documentAvailability()));

        return Inertia::render('Admin/Partners/Index', [
            'applications' => $applications,
            'organizationUpgrades' => $organizationUpgrades,
            'types' => config('partners'),
            'questionnaire' => config('partner_questionnaire'),
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? ''],
        ])
            ->toResponse(request())->header('Cache-Control', 'no-store, private');
    }

    public function updateDetails(Request $request, PartnerApplication $application, AuditRecorder $audit)
    {
        $questions = collect(config('partner_questionnaire'))->flatMap(fn ($section) => $section['questions']);
        $rules = [
            'organisation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'location' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:500'],
            'details' => ['nullable', 'string', 'max:5000'],
            'background_knowledge' => ['nullable', 'string', 'max:2000'],
            'digital_skills' => ['nullable', 'string', 'max:2000'],
            'installation_experience' => ['nullable', 'string', 'max:2000'],
            'networking_experience' => ['nullable', 'string', 'max:2000'],
            'business_skills' => ['nullable', 'string', 'max:2000'],
            'management_skills' => ['nullable', 'string', 'max:2000'],
            'guided_answers' => ['nullable', 'array:'.implode(',', $questions->pluck('key')->all())],
        ];
        foreach ($questions as $question) {
            $key = 'guided_answers.'.$question['key'];
            if ($question['type'] === 'multiple') {
                $rules[$key] = ['sometimes', 'array', 'max:'.count($question['options'])];
                $rules[$key.'.*'] = ['required', 'string', 'distinct', Rule::in($question['options'])];
            } else {
                $rules[$key] = ['sometimes', 'nullable', 'string', Rule::in($question['options'])];
            }
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($application, $request, $data, $audit): void {
            $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($application->status !== 'draft') {
                foreach (['phone', 'location', 'details'] as $field) {
                    if (array_key_exists($field, $data) && blank($data[$field])) {
                        throw ValidationException::withMessages([$field => 'This field is required for a submitted application.']);
                    }
                }
            }
            $application->fill($data);
            $changed = array_keys($application->getDirty());
            $application->save();
            if ($changed) {
                $audit->record('partner.application_details_updated', $request->user(), ['fields' => $changed], $application);
            }
        });

        return back()->with('success', 'Partner application details updated.');
    }

    public function destroy(Request $request, PartnerApplication $application, AuditRecorder $audit)
    {
        $photo = DB::transaction(function () use ($application, $request, $audit): ?string {
            $application->user()->lockForUpdate()->firstOrFail();
            $application = PartnerApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if (PartnerOrganizationUpgrade::where('partner_application_id', $application->id)->exists()) {
                throw ValidationException::withMessages(['application_delete' => 'This application cannot be deleted because it has an organization upgrade record.']);
            }
            if ($application->type === 'sales_agent' && $this->hasCommercialActivity($application->user_id)) {
                throw ValidationException::withMessages(['application_delete' => 'This sales-agent application cannot be deleted because the partner has commercial activity.']);
            }
            $photo = $application->photo_path;
            $audit->record('partner.application_deleted', $request->user(), [
                'partner_user_id' => $application->user_id, 'type' => $application->type, 'status' => $application->status,
            ], $application);
            $application->delete();

            return $photo;
        });
        if ($photo && ! Storage::disk('local')->delete($photo)) {
            report(new RuntimeException('Unable to remove a deleted partner application photo.'));

            return back()->with('error', 'The application was deleted, but its private photo could not be removed. Contact an administrator.');
        }

        return back()->with('success', 'Application deleted. The user account was not deleted.');
    }

    private function hasCommercialActivity(int $userId): bool
    {
        foreach (['customers', 'leads', 'prospective_customers', 'partner_quotations', 'partner_commissions', 'partner_point_entries'] as $table) {
            if (DB::table($table)->where('partner_user_id', $userId)->exists()) {
                return true;
            }
        }

        return DB::table('partner_network_commissions')
            ->where(fn ($query) => $query->where('partner_user_id', $userId)->orWhere('source_partner_user_id', $userId))
            ->exists();
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
