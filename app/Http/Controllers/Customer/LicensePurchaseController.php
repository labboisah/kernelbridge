<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Finance\Services\CustomerCreditService;
use App\Domain\Licensing\Services\PackageChangeService;
use App\Domain\Licensing\Services\PackagePurchaseService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LicensePurchaseController extends Controller
{
    public function store(Request $request, PackagePurchaseService $purchases): RedirectResponse
    {
        $data = $request->validate(['packages' => ['required', 'array', 'min:1', 'max:7'], 'packages.*' => ['required', 'uuid', 'distinct']]);
        $customer = $request->user()->customers()->orderByDesc('customer_user.is_primary')->orderBy('customers.id')->firstOrFail();
        $invoice = $purchases->purchase($customer, $data['packages'], $request->user());

        if ($request->boolean('use_credit')) {
            app(CustomerCreditService::class)->apply($invoice, $request->user());
        }

        return redirect()->route('portal.invoices.show', $invoice)->with('success', 'Invoice prepared. Complete payment to receive your activation code.');
    }

    public function preview(Request $request, Subscription $subscription, PackageChangeService $changes): JsonResponse
    {
        $this->owned($request, $subscription->customer_id);
        $data = $request->validate(['packages' => ['present', 'array', 'max:7'], 'packages.*' => ['uuid', 'distinct'], 'renew' => ['sometimes', 'boolean']]);

        return response()->json($changes->preview($subscription, $data['packages'], $request->boolean('renew')));
    }

    public function change(Request $request, Subscription $subscription, PackageChangeService $changes): RedirectResponse
    {
        $this->owned($request, $subscription->customer_id);
        $data = $request->validate(['packages' => ['present', 'array', 'max:7'], 'packages.*' => ['uuid', 'distinct'], 'use_credit' => ['required', 'boolean']]);
        $invoice = $changes->change($subscription, $data['packages'], $request->user(), $request->boolean('use_credit'));

        return $invoice ? redirect()->route('portal.invoices.show', $invoice)
            : back()->with('success', 'Package selection updated. Any unused paid time was credited. Refresh your client subscription.');
    }

    public function renew(Request $request, Subscription $subscription, PackageChangeService $changes): RedirectResponse
    {
        $this->owned($request, $subscription->customer_id);
        $request->validate(['use_credit' => ['required', 'boolean']]);
        $uuids = array_column(data_get($subscription->metadata, 'package_prices', []), 'uuid');
        $invoice = $changes->change($subscription, $uuids, $request->user(), $request->boolean('use_credit'), true);

        return redirect()->route('portal.invoices.show', $invoice);
    }

    public function cancel(Request $request, SubscriptionPackageOrder $order, PackageChangeService $changes): RedirectResponse
    {
        $subscription = Subscription::query()->findOrFail($order->subscription_id);
        $this->owned($request, $subscription->customer_id);
        $changes->cancel($order, $request->user());

        return back()->with('success', 'Unpaid package invoice cancelled. Previously removed packages and credit are unchanged.');
    }

    public function credit(Request $request, Invoice $invoice, CustomerCreditService $credits): RedirectResponse
    {
        $this->owned($request, $invoice->customer_id);
        $credits->apply($invoice, $request->user());

        return back()->with('success', 'Available account credit applied. Refresh your client subscription after full settlement.');
    }

    private function owned(Request $request, int $customerId): void
    {
        abort_unless($request->user()->customers()->whereKey($customerId)->exists(), 403);
    }
}
