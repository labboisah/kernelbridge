<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Customers\Services\CustomerManager;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerNoteRequest;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['lead', 'prospect', 'active', 'suspended', 'inactive'])],
            'type' => ['nullable', Rule::in(['individual', 'organization'])], 'source' => ['nullable', 'string', 'max:120'], 'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $customers = Customer::query()->with('assignedUser:id,name')->withCount(['contacts', 'products'])
            ->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['assigned_to'] ?? null, fn ($query, $userId) => $query->where('assigned_to', $userId))
            ->latest()->paginate(20)->withQueryString();

        return Inertia::render('Admin/Customers/Index', [
            'customers' => CustomerResource::collection($customers)->response()->getData(true), 'filters' => $filters,
            'assignees' => $this->assignees(), 'sources' => Customer::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source'),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('Admin/Customers/Create', ['products' => $this->products(), 'assignees' => $this->assignees()]);
    }

    public function store(StoreCustomerRequest $request, CustomerManager $manager): RedirectResponse
    {
        $customer = $manager->create($request->validated(), $request->user());

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Customer created successfully.');
    }

    public function show(Request $request, Customer $customer): Response
    {
        $this->authorize('view', $customer);
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'contacts', 'products', 'subscriptions', 'invoices', 'payments', 'licenses', 'support', 'activity'])]])['tab'] ?? 'overview';
        $customer->load(['assignedUser:id,name,email', 'contacts', 'addresses', 'products:id,uuid,name,slug,code,status', 'notes.author:id,name']);
        $activity = DB::table('audit_logs')->latest('created_at')->limit(200)->get()->map(function (object $entry) use ($customer): ?array {
            $metadata = json_decode($entry->metadata, true, flags: JSON_THROW_ON_ERROR);

            return ($metadata['customer_uuid'] ?? null) === $customer->uuid ? ['id' => $entry->id, 'event' => $entry->event, 'metadata' => $metadata, 'created_at' => $entry->created_at] : null;
        })->filter()->values();

        return Inertia::render('Admin/Customers/Show', [
            'customer' => (new CustomerResource($customer))->resolve(), 'activeTab' => $tab, 'activity' => $activity,
            'moduleRecords' => ['subscriptions' => [], 'invoices' => [], 'payments' => [], 'licenses' => [], 'support' => $customer->supportTickets()->with('product:id,name')->get()],
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);
        $customer->load(['contacts', 'addresses', 'products:id']);

        return Inertia::render('Admin/Customers/Edit', ['customer' => (new CustomerResource($customer))->resolve(), 'products' => $this->products(), 'assignees' => $this->assignees()]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, CustomerManager $manager): RedirectResponse
    {
        $manager->update($customer, $request->validated(), $request->user());

        return redirect()->route('admin.customers.show', $customer)->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer, CustomerManager $manager): RedirectResponse
    {
        $this->authorize('delete', $customer);
        $manager->delete($customer, request()->user());

        return redirect()->route('admin.customers.index')->with('success', 'Customer deleted successfully.');
    }

    public function storeNote(StoreCustomerNoteRequest $request, Customer $customer, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $customer, $audit): void {
            $note = $customer->notes()->create([...$request->validated(), 'author_id' => $request->user()->id]);
            $audit->record('customer.note_added', $request->user(), ['customer_uuid' => $customer->uuid, 'note_uuid' => $note->uuid]);
        });

        return back()->with('success', 'Customer note added.');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function assignees()
    {
        return User::role(UserRole::administrative())->orderBy('name')->get(['id', 'name', 'email']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function products()
    {
        return Product::query()->orderBy('sort_order')->get(['id', 'uuid', 'name', 'code', 'slug']);
    }
}
