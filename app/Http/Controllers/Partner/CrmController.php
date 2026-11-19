<?php

namespace App\Http\Controllers\Partner;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CrmController extends Controller
{
    private function leads(Request $request): Builder
    {
        abort_unless($request->user()->isApprovedAgent(), 403, 'An approved sales agent application is required.');

        return Lead::query()->where('partner_user_id', $request->user()->id);
    }

    public function index(Request $request)
    {
        return Inertia::render('Partner/Crm/Index', [
            'leads' => $this->leads($request)->select(['id', 'uuid', 'lead_number', 'name', 'organization', 'status', 'created_at'])->latest()->paginate(20),
            'statuses' => array_column(LeadStatus::cases(), 'value'),
        ]);
    }

    public function show(Request $request, string $lead)
    {
        $lead = $this->leads($request)
            ->select(['id', 'uuid', 'lead_number', 'name', 'organization', 'email', 'phone', 'location', 'follow_up_date', 'status'])
            ->where('uuid', $lead)
            ->with('stageHistory:id,lead_id,status,entered_at')
            ->firstOrFail();

        return Inertia::render('Partner/Crm/Show', [
            'lead' => $lead,
            'statuses' => array_column(LeadStatus::cases(), 'value'),
            'nextStatus' => $lead->status->next()?->value,
        ]);
    }

    public function store(Request $request)
    {
        $this->leads($request);
        $data = $this->validatedDetails($request);

        $lead = DB::transaction(function () use ($request, $data): Lead {
            $lead = new Lead($data + ['source' => 'partner_portal', 'status' => LeadStatus::New]);
            $lead->forceFill(['partner_user_id' => $request->user()->id])->save();
            $lead->update(['lead_number' => sprintf('KBT-LEAD-%06d', $lead->id)]);

            return $lead;
        });

        return redirect()->route('partner.crm.show', $lead->uuid)->with('success', 'CRM lead created.');
    }

    public function update(Request $request, string $lead)
    {
        $lead = $this->leads($request)->where('uuid', $lead)->firstOrFail();
        $lead->update($this->validatedDetails($request));

        return back()->with('success', 'CRM lead updated.');
    }

    public function advance(Request $request, string $lead)
    {
        $query = $this->leads($request);
        $data = $request->validate(['status' => ['required', Rule::enum(LeadStatus::class)]]);

        DB::transaction(function () use ($query, $lead, $data): void {
            $record = $query->where('uuid', $lead)->lockForUpdate()->firstOrFail();
            $status = LeadStatus::from($data['status']);
            if ($record->status->next() === null || ! in_array($status, [$record->status->next(), LeadStatus::Lost], true)) {
                throw ValidationException::withMessages(['status' => 'Choose the next stage or mark this lead as lost. Closed leads cannot be changed here.']);
            }
            $record->update(['status' => $status]);
        });

        return back()->with('success', 'Pipeline stage updated.');
    }

    private function validatedDetails(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'follow_up_date' => ['nullable', 'date'],
        ]);
    }
}
