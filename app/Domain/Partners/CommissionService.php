<?php

namespace App\Domain\Partners;

use App\Models\Invoice;
use App\Models\PartnerCommission;
use App\Models\Product;
use App\Models\ProductCommissionAccrual;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    public function accrue(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->with(['customer', 'items'])->firstOrFail();
            if ($invoice->status->value !== 'paid' || (float) $invoice->balance !== 0.0 || (float) $invoice->total <= 0) {
                return;
            }
            $agent = User::find($invoice->customer->partner_user_id);

            $products = Subscription::where('customer_id', $invoice->customer_id)->where('invoice_id', $invoice->id)->where('status', 'active')->pluck('product_id');
            $order = SubscriptionPackageOrder::where('invoice_id', $invoice->id)->where('status', 'applied')->first();
            if ($order) {
                $products = $products->merge(Subscription::whereKey($order->subscription_id)->where('customer_id', $invoice->customer_id)->where('status', 'active')->pluck('product_id'));
            }
            // Credits do not earn a second commission. Only verified cash settlements count.
            $cash = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->where('payment_allocations.invoice_id', $invoice->id)->where('payments.status', 'verified')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments as refunds')->whereColumn('refunds.parent_payment_id', 'payments.id')->where('refunds.status', 'refunded'))
                ->sum('payment_allocations.amount');
            $cashMinor = $this->minor((string) $cash) + $this->minor((string) $invoice->payments()->where('status', 'successful')->where('method', '!=', 'account_credit')->sum('amount'));
            $totalMinor = $this->minor($invoice->total);
            $cashMinor = min($totalMinor, $cashMinor);
            if ($cashMinor <= 0) {
                return;
            }
            foreach ($products->unique() as $productId) {
                $existing = PartnerCommission::where('invoice_id', $invoice->id)->where('product_id', $productId)->first();
                $accrual = ProductCommissionAccrual::where('invoice_id', $invoice->id)->where('product_id', $productId)->first();
                if (($accrual && $accrual->status === 'earned') || ($existing && ! $accrual)) {
                    continue;
                }
                $product = Product::findOrFail($productId);
                $rates = $accrual?->rates ?? [
                    'vat' => $product->vat_commission_rate, 'developer' => $product->developer_commission_rate,
                    'platform' => $product->platform_commission_rate, 'staff' => $product->staff_commission_rate, 'agent' => $product->partner_commission_rate,
                ];
                if (array_sum(array_map(fn ($rate) => $this->minor((string) $rate), $rates)) !== 10000) {
                    continue;
                }
                $gross = $invoice->items->where('product_id', $productId)->whereNotNull('plan_id')->sum(fn ($item) => $this->minor($item->total));
                $base = (int) round($gross * $cashMinor / $totalMinor);
                if ($base <= 0) {
                    continue;
                }
                $amounts = [];
                $fractions = [];
                foreach ($rates as $party => $rate) {
                    $value = $base * $this->minor((string) $rate);
                    $amounts[$party] = intdiv($value, 10000);
                    $fractions[$party] = $value % 10000;
                }
                $remainder = $base - array_sum($amounts);
                arsort($fractions, SORT_NUMERIC);
                foreach (array_slice(array_keys($fractions), 0, $remainder) as $party) {
                    $amounts[$party]++;
                }
                $accrual ??= new ProductCommissionAccrual;
                $accrual->fill(['invoice_id' => $invoice->id, 'product_id' => $productId, 'currency' => $invoice->currency,
                    'base_amount' => $this->money($base), 'rates' => $rates, 'status' => 'earned',
                    ...collect($amounts)->mapWithKeys(fn ($amount, $party) => [$party.'_amount' => $this->money($amount)])->all(),
                ])->save();
                if ($agent && $agent->isApprovedAgent() && $amounts['agent'] > 0) {
                    $entry = $existing ?? new PartnerCommission;
                    $entry->fill(['partner_user_id' => $existing?->partner_user_id ?? $agent->id, 'invoice_id' => $invoice->id,
                        'product_id' => $productId, 'rate' => $rates['agent'], 'base_amount' => $this->money($base),
                        'amount' => $this->money($amounts['agent']), 'currency' => $invoice->currency, 'status' => 'earned',
                        'earned_at' => now(), 'reversed_at' => null])->save();
                }
            }
        });
    }

    public function reverse(Invoice $invoice): void
    {
        ProductCommissionAccrual::where('invoice_id', $invoice->id)->where('status', 'earned')->update(['status' => 'reversed']);
        PartnerCommission::where('invoice_id', $invoice->id)->where('status', 'earned')->update(['status' => 'reversed', 'reversed_at' => now()]);
    }

    private function minor(string $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function money(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }
}
