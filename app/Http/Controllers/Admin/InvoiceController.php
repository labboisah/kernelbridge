<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance\Services\InvoiceManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInvoicePaymentRequest;
use App\Http\Requests\Admin\StoreInvoiceRequest;
use App\Http\Requests\Admin\UpdateInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class InvoiceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Invoice::class);

        return Inertia::render('Admin/Invoices/Index', ['invoices' => Invoice::with('customer:id,uuid,name,customer_number')->latest()->get()]);
    }

    public function create(): Response
    {
        $this->authorize('create', Invoice::class);

        return Inertia::render('Admin/Invoices/Create', $this->data());
    }

    public function store(StoreInvoiceRequest $r, InvoiceManager $m): RedirectResponse
    {
        $i = $m->create($r->validated(), $r->user());

        return redirect()->route('admin.invoices.show', $i)->with('success', 'Invoice created.');
    }

    public function show(Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);
        $invoice->load(['customer', 'items', 'payments.recorder:id,name', 'quotation:id,uuid,quotation_number']);

        return Inertia::render('Admin/Invoices/Show', ['invoice' => $invoice, 'timeline' => $this->timeline($invoice)]);
    }

    public function edit(Invoice $invoice): Response
    {
        $this->authorize('update', $invoice);

        return Inertia::render('Admin/Invoices/Edit', [...$this->data(), 'invoice' => $invoice->load('items')]);
    }

    public function update(UpdateInvoiceRequest $r, Invoice $invoice, InvoiceManager $m): RedirectResponse
    {
        $m->update($invoice, $r->validated(), $r->user());

        return redirect()->route('admin.invoices.show', $invoice)->with('success', 'Invoice updated.');
    }

    public function issue(Invoice $invoice, InvoiceManager $m): RedirectResponse
    {
        $this->authorize('issue', $invoice);
        $m->issue($invoice, request()->user());

        return back()->with('success', 'Invoice issued.');
    }

    public function payment(StoreInvoicePaymentRequest $r, Invoice $invoice, InvoiceManager $m): RedirectResponse
    {
        $m->payment($invoice, $r->validated(), $r->user());

        return back()->with('success', 'Payment recorded.');
    }

    public function cancel(Invoice $invoice, InvoiceManager $m): RedirectResponse
    {
        $this->authorize('cancel', $invoice);
        $m->cancel($invoice, request()->user());

        return back()->with('success', 'Invoice cancelled.');
    }

    private function data(): array
    {
        return ['customers' => Customer::orderBy('name')->get(['id', 'name', 'customer_number']), 'products' => Product::orderBy('name')->get(['id', 'name']), 'plans' => Plan::orderBy('name')->get(['id', 'name'])];
    }

    private function timeline(Invoice $i): array
    {
        return collect([['event' => 'Invoice created', 'at' => $i->created_at], $i->issued_at ? ['event' => 'Invoice issued', 'at' => $i->issued_at] : null, $i->paid_at ? ['event' => 'Invoice paid', 'at' => $i->paid_at] : null, $i->cancelled_at ? ['event' => 'Invoice cancelled', 'at' => $i->cancelled_at] : null])->filter()->merge($i->payments->map(fn ($p) => ['event' => "Payment {$p->reference} ({$p->currency} {$p->amount})", 'at' => $p->paid_at]))->sortByDesc('at')->values()->all();
    }
}
