<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DemoRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $cards = [];
        if ($request->user()->can('lead.view')) {
            $cards = [...$cards, ['label' => 'New leads', 'value' => Lead::query()->where('status', 'new')->count(), 'route' => 'admin.leads.index'], ['label' => 'Pending demos', 'value' => DemoRequest::query()->whereIn('status', ['pending', 'scheduled'])->count(), 'route' => 'admin.leads.index'], ['label' => 'Open quotations', 'value' => Quotation::query()->whereIn('status', ['draft', 'sent'])->count(), 'route' => 'admin.quotations.index']];
        }
        if ($request->user()->can('invoice.view')) {
            $cards = [...$cards, ['label' => 'Outstanding invoices', 'value' => Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'overdue'])->count(), 'route' => 'admin.invoices.index'], ['label' => 'Pending payments', 'value' => Payment::query()->where('status', 'pending')->count(), 'route' => 'admin.payments.index']];
        }
        if ($request->user()->can('support.view')) {
            $cards = [...$cards, ['label' => 'Open tickets', 'value' => SupportTicket::query()->whereIn('status', ['open', 'in_progress', 'waiting_customer'])->count(), 'route' => 'admin.support.index'], ['label' => 'Unassigned tickets', 'value' => SupportTicket::query()->whereNull('assigned_to')->whereNotIn('status', ['resolved', 'closed'])->count(), 'route' => 'admin.support.index']];
        }
        if ($request->user()->can('customer.view')) {
            $cards[] = ['label' => 'Active customers', 'value' => Customer::query()->where('status', 'active')->count(), 'route' => 'admin.customers.index'];
        }

        return Inertia::render('Staff/Dashboard', ['cards' => $cards, 'roles' => $request->user()->getRoleNames()]);
    }
}
