<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Partners\PartnerPointsService;
use App\Domain\Sales\Services\LeadConversionService;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PartnerClientInvitation;
use App\Models\Product;
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
            ->select(['id', 'uuid', 'lead_number', 'name', 'organization', 'email', 'phone', 'location', 'organization_type', 'number_of_users', 'product_id', 'follow_up_date', 'status', 'converted_customer_id'])
            ->where('uuid', $lead)
            ->with(['stageHistory:id,lead_id,status,entered_at', 'convertedCustomer:id,uuid,customer_number,name,email'])
            ->firstOrFail();
        $customer = $lead->convertedCustomer;
        $clientHasPortalAccess = $customer !== null
            && filled($customer->email)
            && $customer->users()->whereRaw('LOWER(users.email) = ?', [mb_strtolower($customer->email)])->exists();
        $registrationInvitationPending = $customer !== null
            && PartnerClientInvitation::query()
                ->where('customer_id', $customer->id)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->exists();

        return Inertia::render('Partner/Crm/Show', [
            'lead' => $lead,
            'statuses' => array_column(LeadStatus::cases(), 'value'),
            'nextStatus' => $lead->status->next()?->value,
            'products' => Product::published()->get(['id', 'name']),
            'canContinueRegistration' => $customer !== null
                && filled($customer->email)
                && ! $clientHasPortalAccess
                && $request->user()->can('partner.client.invite'),
            'clientHasPortalAccess' => $clientHasPortalAccess,
            'registrationInvitationPending' => $registrationInvitationPending,
        ]);
    }

    public function update(Request $request, string $lead)
    {
        $lead = $this->leads($request)->where('uuid', $lead)->firstOrFail();
        $lead->update($this->validatedDetails($request));

        return back()->with('success', 'CRM lead updated.');
    }

    public function advance(Request $request, string $lead, LeadConversionService $conversion, PartnerPointsService $points)
    {
        $query = $this->leads($request);
        $data = $request->validate(['status' => ['required', Rule::enum(LeadStatus::class)]]);

        DB::transaction(function () use ($query, $lead, $data, $request, $conversion, $points): void {
            $record = $query->where('uuid', $lead)->lockForUpdate()->firstOrFail();
            $status = LeadStatus::from($data['status']);
            if ($record->status->next() === null || ! in_array($status, [$record->status->next(), LeadStatus::Lost], true)) {
                throw ValidationException::withMessages(['status' => 'Choose the next stage or mark this lead as lost. Closed leads cannot be changed here.']);
            }
            if ($status === LeadStatus::Won) {
                $conversion->convert($record, $request->user(), $request->user()->id);
                $points->awardWonLead($record);
            } else {
                $record->update(['status' => $status]);
            }
        });

        return back()->with('success', $data['status'] === LeadStatus::Won->value
            ? 'Opportunity won and added to your clients.'
            : 'Pipeline stage updated.');
    }

    private function validatedDetails(Request $request): array
    {
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
        ]);
        if (! empty($data['product_id']) && ! Product::published()->whereKey($data['product_id'])->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Choose a currently available product.']);
        }

        return $data;
    }
}
