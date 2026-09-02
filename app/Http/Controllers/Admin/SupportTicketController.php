<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Support\Services\SupportTicketService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportMessageRequest;
use App\Http\Requests\UpdateSupportTicketRequest;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupportTicketController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupportTicket::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string'], 'priority' => ['nullable', 'string'], 'category' => ['nullable', 'string'], 'product' => ['nullable', 'uuid'], 'assigned_to' => ['nullable', 'integer']]);
        $tickets = SupportTicket::query()->with(['customer:id,uuid,name,customer_number', 'product:id,uuid,name', 'assignee:id,name'])->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $q) => $q->where('ticket_number', 'like', "%$v%")->orWhere('subject', 'like', "%$v%")))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))->when($filters['product'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($q) => $q->where('uuid', $v)))->when(array_key_exists('assigned_to', $filters), fn ($q) => $q->where('assigned_to', $filters['assigned_to']))->latest('opened_at')->get();

        return Inertia::render('Admin/Support/Index', ['tickets' => $tickets, 'filters' => $filters, 'products' => Product::query()->orderBy('name')->get(['uuid', 'name']), 'agents' => User::permission('support.manage')->orderBy('name')->get(['id', 'name']), 'counts' => ['open' => SupportTicket::query()->where('status', 'open')->count(), 'in_progress' => SupportTicket::query()->where('status', 'in_progress')->count(), 'waiting_customer' => SupportTicket::query()->where('status', 'waiting_customer')->count(), 'overdue' => SupportTicket::query()->whereNull('sla_breached_at')->whereNotIn('status', ['resolved', 'closed'])->where('resolution_due_at', '<', now())->count(), 'unassigned' => SupportTicket::query()->whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed'])->count()]]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $this->authorize('view', $ticket);
        $ticket->load(['customer', 'product:id,uuid,name', 'assignee:id,name', 'messages.sender:id,name', 'messages.attachments']);

        return Inertia::render('Admin/Support/Show', ['ticket' => $ticket, 'agents' => User::permission('support.manage')->orderBy('name')->get(['id', 'name'])]);
    }

    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $service->update($ticket, $request->validated(), $request->user());

        return back()->with('success', 'Ticket updated.');
    }

    public function message(StoreSupportMessageRequest $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $service->message($ticket, $request->validated('body'), $request->file('attachments', []), $request->user(), $request->boolean('internal_note'));

        return back()->with('success', $request->boolean('internal_note') ? 'Internal note added.' : 'Reply sent.');
    }
}
