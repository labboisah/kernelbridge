<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentAssets;
use App\Models\Invoice;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerInvoiceController extends Controller
{
    public function show(Invoice $invoice, DocumentAssets $assets): Response
    {
        return Inertia::render('Invoices/CustomerView', ['invoice' => $invoice->load(['customer', 'items', 'payments']), 'documentAssets' => $assets->images()]);
    }
}
