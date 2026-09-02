<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerInvoiceController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        return Inertia::render('Invoices/CustomerView', ['invoice' => $invoice->load(['customer', 'items', 'payments'])]);
    }
}
