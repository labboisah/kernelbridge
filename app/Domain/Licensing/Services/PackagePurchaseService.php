<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PackagePurchaseService
{
    public function __construct(private InvoiceManager $invoices, private SubscriptionService $subscriptions) {}

    public function purchase(Customer $customer, array $uuids, User $actor, array $implementation = []): Invoice
    {
        $invoice = DB::transaction(function () use ($customer, $uuids, $actor, $implementation): Invoice {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $plans = Plan::query()->publiclyAvailable()->whereIn('uuid', $uuids)
                ->whereIn('slug', array_keys(config('mediflow.packages')))
                ->whereHas('product', fn ($q) => $q->published()->where('code', 'MEDIFLOW'))
                ->lockForUpdate()->get();
            if ($plans->count() !== count($uuids) || $plans->isEmpty()) {
                throw ValidationException::withMessages(['packages' => 'Select available MediFlow packages.']);
            }
            $primary = $plans->firstWhere('uuid', $uuids[0]);
            if ($plans->contains(fn (Plan $p) => $p->price === null || (float) $p->price <= 0)
                || $plans->pluck('currency')->unique()->count() !== 1
                || $plans->map(fn (Plan $p) => $p->billing_cycle->value)->unique()->count() !== 1
                || ! in_array($primary->billing_cycle->value, ['monthly', 'quarterly', 'semiannual', 'annual'], true)) {
                throw ValidationException::withMessages(['packages' => 'Packages need configured positive prices, the same currency, and the same recurring billing period. Contact sales for a quote.']);
            }
            if (Subscription::query()->where('customer_id', $customer->id)->where('product_id', $primary->product_id)
                ->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->exists()) {
                throw ValidationException::withMessages(['packages' => 'You already have a MediFlow service. Contact support to change its packages.']);
            }
            $implementation = app(ImplementationPricing::class)->selection($primary, $implementation);
            $items = app(ImplementationPricing::class)->lines($implementation, $primary->product_id);
            $recurringMinor = 0;
            $modules = [];
            foreach ($plans as $plan) {
                $recurringMinor += $this->minor($plan->price);
                $items[] = ['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => 'MediFlow - '.$plan->name, 'quantity' => 1, 'unit_price' => $plan->price, 'discount' => 0, 'tax' => 0];
                if ((float) $plan->setup_fee > 0) {
                    $items[] = ['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => $plan->name.' - one-time setup', 'quantity' => 1, 'unit_price' => $plan->setup_fee, 'discount' => 0, 'tax' => 0];
                }
                $packageModules = config('mediflow.packages.'.$plan->slug.'.modules');
                $modules = array_merge($modules, in_array('*', $packageModules, true) ? array_keys(config('mediflow.modules')) : $packageModules);
            }
            $invoice = $this->invoices->create([
                'customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => today()->addDays(7),
                'currency' => $primary->currency, 'items' => $items,
                'notes' => 'MediFlow packages for one licensed computer.',
                'terms' => 'An activation code is issued after full payment is verified. This licence is bound to the first activated computer.',
            ], $actor);
            $this->subscriptions->create($customer, $primary, $invoice, $actor, [
                'price' => number_format($recurringMinor / 100, 2, '.', ''), 'auto_renew' => false,
                'metadata' => [
                    'implementation' => $implementation, 'checkout' => true, 'device_bound' => true, 'primary_package' => $primary->slug,
                    'selected_packages' => $plans->pluck('slug')->values()->all(),
                    'package_names' => $plans->pluck('name')->values()->all(),
                    'licensed_modules' => array_values(array_unique($modules)),
                    'package_prices' => $plans->map(fn (Plan $p) => ['uuid' => $p->uuid, 'key' => $p->slug, 'price' => $p->price, 'setup_fee' => $p->setup_fee])->all(),
                ],
            ]);

            return $invoice;
        });
        $this->invoices->issue($invoice, $actor);

        return $invoice;
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
