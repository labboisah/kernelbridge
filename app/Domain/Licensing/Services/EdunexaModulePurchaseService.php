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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class EdunexaModulePurchaseService
{
    public function __construct(private InvoiceManager $invoices, private SubscriptionService $subscriptions) {}

    public function purchase(Customer $customer, string $planUuid, User $actor): Invoice
    {
        $invoice = DB::transaction(function () use ($customer, $planUuid, $actor): Invoice {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $product = \App\Models\Product::query()->where('code', 'EDUNEXA')->firstOrFail();
            $plan = Plan::query()->with('features')->where('product_id', $product->id)->where('uuid', $planUuid)->lockForUpdate()->firstOrFail();
            if (! $plan->is_active || ! $plan->is_public || $plan->price === null || (float) $plan->price <= 0) {
                throw ValidationException::withMessages(['plan_uuid' => 'Choose an available, priced EduNexa plan. Enterprise plans require a quote from sales.']);
            }

            $enabledFeatures = $plan->features->keyBy('feature_key');
            $modules = [];
            $licensedFeatures = [];
            foreach (config('edunexa.modules', []) as $module) {
                $moduleFeatureKey = 'module_'.Str::snake($module['key']);
                $moduleFeature = $enabledFeatures->get($moduleFeatureKey);
                if (! $moduleFeature || ! $moduleFeature->enabled) continue;

                $prefix = Str::snake($module['key'].'_');
                $features = $enabledFeatures->filter(fn ($feature, $key) => str_starts_with($key, $prefix) && $feature->enabled)
                    ->map(fn ($feature) => ['key' => $feature->feature_key, 'name' => $feature->feature_name,
                        'description' => $feature->description, 'enabled' => true])->values()->all();
                $licensedFeatures[] = $moduleFeatureKey;
                $licensedFeatures = array_merge($licensedFeatures, array_column($features, 'key'));
                $modules[] = [
                    'key' => $module['key'], 'module_code' => $module['key'], 'name' => $module['name'],
                    'description' => $module['description'], 'features' => $features,
                ];
            }

            if (! in_array(config('edunexa.core_module'), array_column($modules, 'module_code'), true)) {
                throw ValidationException::withMessages(['plan_uuid' => 'The selected plan must include Core School Operations. Ask an administrator to update its features.']);
            }
            if (! count($modules)) {
                throw ValidationException::withMessages(['plan_uuid' => 'This plan has no enabled EduNexa modules.']);
            }
            if (Subscription::query()->where('customer_id', $customer->id)->where('product_id', $product->id)
                ->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->exists()) {
                throw ValidationException::withMessages(['plan_uuid' => 'This account already has an EduNexa service. Manage its existing subscription or contact support.']);
            }

            $moduleNames = array_column($modules, 'name');
            $items = [[
                'product_id' => $product->id, 'plan_id' => $plan->id,
                'description' => 'EduNexa '.$plan->name.' plan ('.$plan->billing_cycle->value.') including: '.implode(', ', $moduleNames),
                'quantity' => 1, 'unit_price' => $plan->price, 'discount' => 0, 'tax' => 0,
            ]];
            if ((float) $plan->setup_fee > 0) {
                $items[] = ['product_id' => $product->id, 'plan_id' => $plan->id,
                    'description' => 'EduNexa '.$plan->name.' one-time setup', 'quantity' => 1,
                    'unit_price' => $plan->setup_fee, 'discount' => 0, 'tax' => 0];
            }

            $invoice = $this->invoices->create([
                'customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => today()->addDays(7),
                'currency' => $plan->currency, 'items' => $items,
                'notes' => 'EduNexa '.$plan->name.' plan. Included modules: '.implode(', ', $moduleNames).'.',
                'terms' => 'Your one-computer activation licence and private client configuration are issued after full payment is verified. Included modules and features are snapshotted from the selected plan.',
            ], $actor);

            $this->subscriptions->create($customer, $plan, $invoice, $actor, [
                'price' => $plan->price, 'currency' => $plan->currency, 'auto_renew' => false,
                'metadata' => [
                    'checkout' => true, 'edunexa_plan' => $plan->slug, 'device_bound' => true,
                    'primary_package' => config('edunexa.core_module'),
                    'selected_packages' => array_column($modules, 'module_code'),
                    'package_names' => [$plan->name], 'licensed_modules' => array_values(array_unique($licensedFeatures)),
                    'package_prices' => array_map(fn ($module) => [
                        'key' => $module['key'], 'configuration' => $module,
                    ], $modules), 'configuration_revision' => 1,
                ],
            ]);

            return $invoice;
        });

        $this->invoices->issue($invoice, $actor);

        return $invoice;
    }
}