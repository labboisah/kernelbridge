<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Sales\Services\QuotationManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreQuotationRequest;
use App\Http\Requests\Admin\UpdateQuotationRequest;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class QuotationController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Quotation::class);

        return Inertia::render('Admin/Quotations/Index', ['quotations' => Quotation::query()->with('customer:id,uuid,name,customer_number')->latest()->get()]);
    }

    public function create(): Response
    {
        $this->authorize('create', Quotation::class);

        return Inertia::render('Admin/Quotations/Create', $this->formData());
    }

    public function store(StoreQuotationRequest $r, QuotationManager $m): RedirectResponse
    {
        $q = $m->create($r->validated(), $r->user());

        return redirect()->route('admin.quotations.show', $q)->with('success', 'Quotation created.');
    }

    public function show(Quotation $quotation): Response
    {
        $this->authorize('view', $quotation);

        return Inertia::render('Admin/Quotations/Show', ['quotation' => $quotation->load(['customer', 'items', 'creator:id,name', 'invoice'])]);
    }

    public function edit(Quotation $quotation): Response
    {
        $this->authorize('update', $quotation);

        return Inertia::render('Admin/Quotations/Edit', [...$this->formData(), 'quotation' => $quotation->load('items')]);
    }

    public function update(UpdateQuotationRequest $r, Quotation $quotation, QuotationManager $m): RedirectResponse
    {
        $m->update($quotation, $r->validated(), $r->user());

        return redirect()->route('admin.quotations.show', $quotation)->with('success', 'Quotation updated.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        $this->authorize('delete', $quotation);
        $quotation->delete();

        return redirect()->route('admin.quotations.index')->with('success', 'Quotation deleted.');
    }

    public function send(Quotation $quotation, QuotationManager $m): RedirectResponse
    {
        $this->authorize('send', $quotation);
        $m->send($quotation, request()->user());

        return back()->with('success', 'Quotation sent to customer.');
    }

    public function convert(Quotation $quotation, QuotationManager $m): RedirectResponse
    {
        $this->authorize('convert', $quotation);
        $invoice = $m->convert($quotation, request()->user());

        return back()->with('success', "Converted to invoice {$invoice->invoice_number}.");
    }

    private function formData(): array
    {
        return ['customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'customer_number', 'email']), 'products' => Product::query()->orderBy('name')->get(['id', 'name']), 'plans' => Plan::query()->with('product:id,name')->orderBy('name')->get(['id', 'product_id', 'name', 'price', 'currency'])];
    }
}
