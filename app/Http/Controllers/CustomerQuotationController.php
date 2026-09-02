<?php

namespace App\Http\Controllers;

use App\Domain\Sales\Services\QuotationManager;
use App\Models\Quotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerQuotationController extends Controller
{
    public function show(Quotation $quotation): Response
    {
        $expiry = now()->addDays(30);

        return Inertia::render('Quotations/CustomerView', ['quotation' => $quotation->load(['customer', 'items']), 'acceptUrl' => URL::temporarySignedRoute('quotations.customer.accept', $expiry, ['quotation' => $quotation]), 'rejectUrl' => URL::temporarySignedRoute('quotations.customer.reject', $expiry, ['quotation' => $quotation])]);
    }

    public function accept(Quotation $quotation, QuotationManager $m): RedirectResponse
    {
        $m->decide($quotation, 'accepted');

        return back()->with('success', 'Quotation accepted. Thank you.');
    }

    public function reject(Quotation $quotation, QuotationManager $m): RedirectResponse
    {
        $m->decide($quotation, 'rejected');

        return back()->with('success', 'Quotation rejected.');
    }
}
