<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Security\Contracts\FileScanner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePaymentRequest;
use App\Http\Requests\Admin\VerifyPaymentRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PaymentController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Payment::class);

        return Inertia::render('Admin/Payments/Index', ['payments' => Payment::query()->with('customer:id,name,customer_number')->latest()->get()]);
    }

    public function create(): Response
    {
        $this->authorize('create', Payment::class);

        return Inertia::render('Admin/Payments/Create', ['customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'customer_number'])]);
    }

    public function store(StorePaymentRequest $request, PaymentManager $manager, FileScanner $scanner): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('proof_of_payment')) {
            $scanner->assertClean($request->file('proof_of_payment'));
            $data['proof_of_payment'] = $request->file('proof_of_payment')->store('payment-proofs', 'public');
        }
        $payment = $manager->create($data, $request->user());

        return redirect()->route('admin.payments.show', $payment)->with('success', 'Payment recorded for verification.');
    }

    public function show(Payment $payment): Response
    {
        $this->authorize('view', $payment);
        $payment->load(['customer', 'verifier:id,name', 'recorder:id,name', 'allocations.invoice']);
        $invoices = Invoice::query()->where('customer_id', $payment->customer_id)->where('currency', $payment->currency)->whereIn('status', ['issued', 'partially_paid', 'overdue'])->where('balance', '>', 0)->orderBy('due_date')->get(['id', 'uuid', 'invoice_number', 'total', 'balance', 'currency', 'due_date']);

        return Inertia::render('Admin/Payments/Show', ['payment' => $payment, 'invoices' => $invoices]);
    }

    public function verify(VerifyPaymentRequest $request, Payment $payment, PaymentManager $manager): RedirectResponse
    {
        $manager->verify($payment, $request->validated('allocations'), $request->user());

        return back()->with('success', 'Payment verified and allocated.');
    }

    public function reject(Payment $payment, PaymentManager $manager): RedirectResponse
    {
        $this->authorize('reject', $payment);
        request()->validate(['notes' => ['required', 'string', 'max:5000']]);
        $manager->reject($payment, request()->user(), request('notes'));

        return back()->with('success', 'Payment rejected.');
    }

    public function refund(Payment $payment, PaymentManager $manager): RedirectResponse
    {
        $this->authorize('refund', $payment);
        request()->validate(['reason' => ['required', 'string', 'max:5000']]);
        $refund = $manager->refund($payment, request()->user(), request('reason'));

        return redirect()->route('admin.payments.show', $refund)->with('success', 'Refund record created and allocations reversed.');
    }
}
