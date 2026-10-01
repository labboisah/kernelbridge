<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FlutterwavePaymentService;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class FlutterwavePaymentController extends Controller
{
    public function initialize(Request $request, Invoice $invoice, FlutterwavePaymentService $service): Response
    {
        abort_unless($request->user()->customers()->whereKey($invoice->customer_id)->exists(), 403);
        $checkoutUrl = $service->initialize($invoice, $request->user());

        return $request->header('X-Inertia') ? Inertia::location($checkoutUrl) : redirect()->away($checkoutUrl);
    }

    public function callback(Request $request, FlutterwavePaymentService $service): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string'], 'tx_ref' => ['required', 'string', 'max:255'], 'transaction_id' => ['required', 'string', 'max:255']]);
        if ($data['status'] !== 'successful') {
            return redirect()->route($request->user()?->isApprovedAgent() ? 'partner.invoices' : 'portal.invoices.index')->with('error', 'The online payment was not completed.');
        }
        try {
            $invoice = $service->verifyAndComplete($data['transaction_id'], $data['tx_ref']);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route($request->user()?->isApprovedAgent() ? 'partner.invoices' : 'portal.invoices.index')->with('error', 'Payment verification is pending. Please check again shortly or contact support.');
        }

        return redirect()->route($request->user()?->isApprovedAgent() && \App\Models\Customer::whereKey($invoice->customer_id)->where('partner_user_id', $request->user()->id)->exists() ? 'partner.invoices.show' : 'portal.invoices.show', $invoice->uuid)->with('success', 'Payment verified. Your service is now active.');
    }

    public function webhook(Request $request, FlutterwavePaymentService $service): JsonResponse
    {
        $secret = (string) config('services.flutterwave.webhook_secret_hash');
        $signature = (string) $request->header('flutterwave-signature');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        abort_if($secret === '' || $signature === '' || ! hash_equals($expected, $signature), 401);
        $transactionId = (string) data_get($request->all(), 'data.id');
        if ($transactionId !== '') {
            try {
                $service->verifyAndComplete($transactionId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['received' => true]);
    }
}
