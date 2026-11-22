<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Partners\PartnerPointsService;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Product;
use App\Models\ProspectiveCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ProspectiveCustomerController extends Controller
{
    private function prospects(Request $request)
    {
        abort_unless($request->user()->isApprovedAgent(), 403, 'An approved sales agent application is required.');

        return ProspectiveCustomer::query()->where('partner_user_id', $request->user()->id);
    }

    public function index(Request $request)
    {
        return Inertia::render('Partner/Pcr/Index', [
            'prospects' => $this->prospects($request)
                ->with('product:id,name')
                ->latest()
                ->paginate(20),
            'products' => Product::published()->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, string $prospect)
    {
        $prospect = $this->prospects($request)
            ->with(['product:id,name', 'convertedLead:id,uuid,lead_number,status'])
            ->where('uuid', $prospect)
            ->firstOrFail();

        return Inertia::render('Partner/Pcr/Show', ['prospect' => $prospect]);
    }

    public function store(Request $request, AuditRecorder $audit, PartnerPointsService $points)
    {
        $this->prospects($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'organization_type' => ['nullable', Rule::in(['school', 'university', 'healthcare', 'business', 'hospitality', 'government', 'nonprofit', 'other'])],
            'number_of_users' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('status', 'active')],
            'follow_up_date' => ['nullable', 'date'],
            'initial_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $prospect = DB::transaction(function () use ($request, $data, $audit, $points): ProspectiveCustomer {
            $prospect = new ProspectiveCustomer($data);
            $prospect->forceFill(['partner_user_id' => $request->user()->id])->save();
            $points->awardProspect($prospect, 'pcr_registered', 1);
            $audit->record('partner.pcr.created', $request->user(), ['pcr_uuid' => $prospect->uuid], $prospect);

            return $prospect;
        });

        return redirect()->route('partner.pcr.show', $prospect->uuid)->with('success', 'Prospective customer registered.');
    }

    public function recordContact(Request $request, string $prospect, AuditRecorder $audit, PartnerPointsService $points)
    {
        $query = $this->prospects($request);
        $interested = $request->input('outcome') === 'interested';
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['interested', 'consent_pending', 'not_interested'])],
            'consent' => $interested ? ['required', 'accepted'] : ['nullable'],
            'consent_method' => $interested ? ['required', Rule::in(['verbal', 'email', 'written'])] : ['nullable'],
            'consented_by_name' => $interested ? ['required', 'string', 'max:255'] : ['nullable'],
            'consent_details' => ['nullable', 'string', 'max:2000'],
            'contact_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $lead = DB::transaction(function () use ($query, $prospect, $request, $data, $audit, $points): ?Lead {
            $record = $query->with('product')->where('uuid', $prospect)->lockForUpdate()->firstOrFail();
            if (! in_array($record->status, ['prospective', 'interested_pending_consent'], true)) {
                throw ValidationException::withMessages(['outcome' => 'This PCR record has already been processed.']);
            }

            $firstContact = $record->contacted_at === null;
            $record->forceFill([
                'contacted_at' => $record->contacted_at ?? now(),
                'contact_notes' => $data['contact_notes'] ?? $record->contact_notes,
            ]);
            if ($firstContact) {
                $points->awardProspect($record, 'visit_recorded', 2);
            }

            if ($data['outcome'] === 'not_interested') {
                $record->status = 'not_interested';
                $record->save();
                $audit->record('partner.pcr.not_interested', $request->user(), ['pcr_uuid' => $record->uuid], $record);

                return null;
            }

            if ($data['outcome'] === 'consent_pending') {
                $record->status = 'interested_pending_consent';
                $record->interested_at = $record->interested_at ?? now();
                $record->save();
                $audit->record('partner.pcr.consent_pending', $request->user(), ['pcr_uuid' => $record->uuid], $record);

                return null;
            }

            $record->status = 'converted';
            $record->interested_at = $record->interested_at ?? now();
            $record->consent_at = now();
            $record->consent_method = $data['consent_method'];
            $record->consented_by_name = $data['consented_by_name'];
            $record->consent_details = $data['consent_details'] ?? null;
            $record->save();
            $points->awardProspect($record, 'consent_recorded', 5);
            $audit->record('partner.pcr.consent_recorded', $request->user(), [
                'pcr_uuid' => $record->uuid,
                'consent_method' => $record->consent_method,
                'consented_by_name' => $record->consented_by_name,
            ], $record);

            $lead = new Lead([
                'name' => $record->name,
                'organization' => $record->organization,
                'email' => $record->email,
                'phone' => $record->phone,
                'location' => $record->location,
                'organization_type' => $record->organization_type,
                'number_of_users' => $record->number_of_users,
                'product_id' => $record->product_id,
                'source' => 'partner_pcr',
                'status' => LeadStatus::Contacted,
                'follow_up_date' => $record->follow_up_date,
                'metadata' => [
                    'pcr_uuid' => $record->uuid,
                    'consent' => [
                        'recorded_at' => $record->consent_at->toISOString(),
                        'method' => $record->consent_method,
                        'consented_by_name' => $record->consented_by_name,
                        'details' => $record->consent_details,
                    ],
                ],
            ]);
            $lead->forceFill(['partner_user_id' => $request->user()->id])->save();
            $lead->update(['lead_number' => sprintf('KBT-LEAD-%06d', $lead->id)]);
            $record->converted_lead_id = $lead->id;
            $record->save();
            $lead->activities()->create([
                'actor_id' => $request->user()->id,
                'type' => 'pcr_converted',
                'description' => 'Prospect expressed interest and consented to CRM follow-up.',
                'metadata' => ['pcr_uuid' => $record->uuid, 'consent_method' => $record->consent_method],
            ]);

            return $lead;
        });

        if (! $lead) {
            return redirect()->route('partner.pcr.index')->with('success', $data['outcome'] === 'consent_pending'
                ? 'Interest recorded. This prospect stays in the PCR until they consent to CRM follow-up.'
                : 'Contact outcome recorded. The prospect was not added to the CRM.');
        }

        return redirect()->route('partner.crm.show', $lead->uuid)->with('success', 'Consent recorded and prospect added to the CRM pipeline.');
    }
}
