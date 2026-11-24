<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Support\Services\SupportTicketService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportMessageRequest;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Http\Requests\UpdateRequesterSupportTicketRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupportTicketController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = $request->user()->customers()->firstOrFail();
        $this->authorize('viewAny', SupportTicket::class);

        return Inertia::render('Customer/Support/Index', [
            'tickets' => $customer->supportTickets()->with(['product:id,name', 'team:id,name'])->get(),
            'counts' => [
                'open' => $customer->supportTickets()->whereIn('status', ['open', 'in_progress', 'waiting_customer'])->count(),
                'resolved' => $customer->supportTickets()->where('status', 'resolved')->count(),
                'closed' => $customer->supportTickets()->where('status', 'closed')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $customer = $request->user()->customers()->firstOrFail();
        $this->authorize('create', SupportTicket::class);

        return Inertia::render('Customer/Support/Create', ['products' => $this->productsFor($customer), 'teams' => $this->teams()]);
    }

    public function store(StoreSupportTicketRequest $request, SupportTicketService $service): RedirectResponse
    {
        $customer = $request->user()->customers()->firstOrFail();
        $ticket = $service->create($customer, $request->validated(), $request->user());

        return redirect()->route('support.show', $ticket)->with('success', 'Support ticket created.');
    }

    public function show(SupportTicket $ticket): Response
    {
        $this->authorize('view', $ticket);
        $ticket->load(['product:id,uuid,name', 'team:id,uuid,name', 'assignee:id,name', 'messages' => fn ($query) => $query->where('is_customer_visible', true)->with(['sender:id,name', 'attachments'])]);

        return Inertia::render('Customer/Support/Show', [
            'ticket' => $ticket,
            'products' => $this->productsFor($ticket->customer),
            'teams' => $this->teams(),
        ]);
    }

    public function message(StoreSupportMessageRequest $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $service->message($ticket, $request->validated('body'), $request->file('attachments', []), $request->user());

        return back()->with('success', 'Reply sent.');
    }

    public function update(UpdateRequesterSupportTicketRequest $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $service->updateByRequester($ticket, $request->validated(), $request->user());

        return back()->with('success', 'Support ticket updated.');
    }

    public function resolve(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $this->authorize('resolveByRequester', $ticket);
        $service->resolveByRequester($ticket, $request->user());

        return back()->with('success', 'Support ticket marked as resolved.');
    }

    private function productsFor(Customer $customer)
    {
        $productIds = $customer->products()->pluck('products.id')->merge(
            $customer->subscriptions()->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->pluck('product_id'),
        )->unique();

        return Product::query()->published()->whereIn('id', $productIds)->get(['uuid', 'name']);
    }

    private function teams()
    {
        return SupportTeam::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['uuid', 'name', 'description']);
    }
}
