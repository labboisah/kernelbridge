<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Sales\Services\LeadConversionService;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLeadNoteRequest;
use App\Http\Requests\Admin\UpdateDemoRequest;
use App\Http\Requests\Admin\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\DemoRequest;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['table', 'pipeline'])], 'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(LeadStatus::class)], 'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'], 'follow_up' => ['nullable', Rule::in(['overdue', 'today', 'upcoming', 'none'])],
        ]);
        $leads = Lead::query()->with(['product:id,name,code,slug', 'assignedUser:id,name'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['product_id'] ?? null, fn ($query, $value) => $query->where('product_id', $value))
            ->when($filters['assigned_to'] ?? null, fn ($query, $value) => $query->where('assigned_to', $value))
            ->when(($filters['follow_up'] ?? null) === 'overdue', fn ($query) => $query->whereDate('follow_up_date', '<', today()))
            ->when(($filters['follow_up'] ?? null) === 'today', fn ($query) => $query->whereDate('follow_up_date', today()))
            ->when(($filters['follow_up'] ?? null) === 'upcoming', fn ($query) => $query->whereDate('follow_up_date', '>', today()))
            ->when(($filters['follow_up'] ?? null) === 'none', fn ($query) => $query->whereNull('follow_up_date'))
            ->latest()->limit(500)->get();

        return Inertia::render('Admin/Leads/Index', [
            'leads' => LeadResource::collection($leads)->resolve(), 'filters' => $filters,
            'statuses' => array_column(LeadStatus::cases(), 'value'), 'products' => Product::query()->orderBy('name')->get(['id', 'name']), 'salesOfficers' => $this->salesOfficers(),
        ]);
    }

    public function show(Lead $lead): Response
    {
        $this->authorize('view', $lead);
        $lead->load(['product:id,name,code,slug', 'assignedUser:id,name,email', 'convertedCustomer:id,uuid,customer_number,name', 'demoRequests.product:id,name', 'demoRequests.assignedUser:id,name', 'activities.actor:id,name', 'notes.author:id,name']);

        return Inertia::render('Admin/Leads/Show', [
            'lead' => (new LeadResource($lead))->resolve(), 'statuses' => array_column(LeadStatus::cases(), 'value'),
            'products' => Product::query()->orderBy('name')->get(['id', 'name']), 'salesOfficers' => $this->salesOfficers(),
        ]);
    }

    public function update(UpdateLeadRequest $request, Lead $lead, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $lead, $audit): void {
            $before = $lead->only(['status', 'assigned_to', 'follow_up_date', 'product_id']);
            $lead->update($request->validated());
            $lead->activities()->create(['actor_id' => $request->user()->id, 'type' => 'lead_updated', 'description' => 'Lead pipeline details updated.', 'metadata' => ['before' => $before, 'after' => $lead->only(array_keys($before))]]);
            $audit->record('lead.updated', $request->user(), ['lead_uuid' => $lead->uuid, 'before' => $before, 'after' => $lead->only(array_keys($before))]);
        });

        return back()->with('success', 'Lead updated successfully.');
    }

    public function storeNote(StoreLeadNoteRequest $request, Lead $lead, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $lead, $audit): void {
            $note = $lead->notes()->create([...$request->validated(), 'author_id' => $request->user()->id]);
            $lead->activities()->create(['actor_id' => $request->user()->id, 'type' => 'note_added', 'description' => 'Internal note added.', 'metadata' => ['note_uuid' => $note->uuid]]);
            $audit->record('lead.note_added', $request->user(), ['lead_uuid' => $lead->uuid, 'note_uuid' => $note->uuid]);
        });

        return back()->with('success', 'Lead note added.');
    }

    public function updateDemo(UpdateDemoRequest $request, DemoRequest $demoRequest, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $demoRequest, $audit): void {
            $before = $demoRequest->only(['status', 'scheduled_at', 'assigned_to']);
            $demoRequest->update($request->validated());
            $leadStatus = match ($demoRequest->status->value) {
                'scheduled' => 'demo_scheduled', 'completed' => 'demo_completed', default => null
            };
            if ($leadStatus && $demoRequest->lead) {
                $demoRequest->lead->update(['status' => $leadStatus]);
            }
            $demoRequest->lead?->activities()->create(['actor_id' => $request->user()->id, 'type' => 'demo_updated', 'description' => "Demo marked {$demoRequest->status->value}.", 'metadata' => ['demo_request_uuid' => $demoRequest->uuid, 'before' => $before]]);
            $audit->record('demo.updated', $request->user(), ['lead_uuid' => $demoRequest->lead?->uuid, 'demo_request_uuid' => $demoRequest->uuid, 'before' => $before, 'after' => $demoRequest->only(['status', 'scheduled_at', 'assigned_to'])]);
        });

        return back()->with('success', 'Demo updated successfully.');
    }

    public function convert(Lead $lead, LeadConversionService $conversion): RedirectResponse
    {
        $this->authorize('convert', $lead);
        $customer = $conversion->convert($lead, request()->user());

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Lead converted to customer successfully.');
    }

    private function salesOfficers(): Collection
    {
        return User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', [UserRole::Sales->value, UserRole::Admin->value, UserRole::SuperAdmin->value]))->orderBy('name')->get(['id', 'name', 'email']);
    }
}
