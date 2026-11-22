<?php

namespace App\Domain\Partners;

use App\Models\Invoice;
use App\Models\BillingSetting;
use App\Models\PartnerApplication;
use App\Models\PartnerCommission;
use App\Models\PartnerNetworkCommission;
use App\Models\Product;
use App\Models\ProductCommissionAccrual;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    public function __construct(private readonly PartnerQualificationService $qualification) {}

    public function accrue(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->with(['customer', 'items'])->firstOrFail();
            if ($invoice->status->value !== 'paid' || (float) $invoice->balance !== 0.0 || (float) $invoice->total <= 0) {
                return;
            }
            $agent = User::find($invoice->customer->partner_user_id);
            $partnerCategory = $agent && $agent->isApprovedAgent()
                ? PartnerApplication::query()->where('user_id', $agent->id)->where('type', 'sales_agent')->value('partner_category')
                : 'individual';

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
                $rates = $accrual?->rates;
                if (! $rates) {
                    $individualRate = $this->minor((string) $product->partner_commission_rate);
                    $agentRate = $partnerCategory === 'organization'
                        ? $this->minor((string) $product->organization_partner_commission_rate)
                        : $individualRate;
                    $platformRate = $this->minor((string) $product->platform_commission_rate) + $individualRate - $agentRate;
                    $rates = [
                        'vat' => $product->vat_commission_rate,
                        'developer' => $product->developer_commission_rate,
                        'platform' => $this->percent($platformRate),
                        'staff' => $product->staff_commission_rate,
                        'agent' => $this->percent($agentRate),
                    ];
                }
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
                $this->accrueNetwork($invoice, $productId, $base, $amounts['platform']);
            }
        });
    }

    public function reverse(Invoice $invoice): void
    {
        ProductCommissionAccrual::where('invoice_id', $invoice->id)->where('status', 'earned')->update(['status' => 'reversed']);
        PartnerCommission::where('invoice_id', $invoice->id)->where('status', 'earned')->update(['status' => 'reversed', 'reversed_at' => now()]);
        PartnerNetworkCommission::where('invoice_id', $invoice->id)->where('status', '!=', 'reversed')
            ->update(['status' => 'reversed', 'reversed_at' => now()]);
    }

    private function accrueNetwork(Invoice $invoice, int $productId, int $paymentBase, int $platformAmount): void
    {
        $settings = BillingSetting::findOrFail(1);
        $sourcePartnerId = $invoice->customer->partner_user_id;
        if (! $settings->partner_network_sharing_enabled || $settings->partner_network_pool_rate <= 0
            || $platformAmount <= 0 || ! $sourcePartnerId
            || ! User::find($sourcePartnerId)?->isApprovedAgent()) {
            return;
        }

        $poolRate = $this->minor((string) $settings->partner_network_pool_rate);
        $poolAmount = min($platformAmount, $this->percentage($paymentBase, $poolRate));
        if ($poolAmount <= 0) {
            return;
        }

        $weights = [
            'qualified' => $this->minor((string) $settings->partner_network_qualified_weight),
            'premium' => $this->minor((string) $settings->partner_network_premium_weight),
            'elite' => $this->minor((string) $settings->partner_network_elite_weight),
        ];
        $eligible = $this->qualification->approvedPartnerQualifications()
            ->filter(fn (array $partner) => $partner['network_eligible'] && $weights[$partner['level']] > 0)
            ->map(fn (array $partner) => $partner + ['weight_minor' => $weights[$partner['level']]])
            ->sortBy('user_id')
            ->values();
        $totalWeight = $eligible->sum('weight_minor');
        if ($totalWeight <= 0) {
            return;
        }

        $allocations = [];
        $fractions = [];
        $wholeUnits = intdiv($poolAmount, $totalWeight);
        $remainingUnits = $poolAmount % $totalWeight;
        foreach ($eligible as $partner) {
            $weightedRemainder = $remainingUnits * $partner['weight_minor'];
            $allocations[$partner['user_id']] = $wholeUnits * $partner['weight_minor'] + intdiv($weightedRemainder, $totalWeight);
            $fractions[$partner['user_id']] = $weightedRemainder % $totalWeight;
        }
        $remaining = $poolAmount - array_sum($allocations);
        arsort($fractions, SORT_NUMERIC);
        foreach (array_slice(array_keys($fractions), 0, $remaining) as $partnerId) {
            $allocations[$partnerId]++;
        }

        $latestSequences = PartnerNetworkCommission::query()
            ->where('invoice_id', $invoice->id)
            ->where('product_id', $productId)
            ->select('partner_user_id')
            ->selectRaw('MAX(earning_sequence) as latest_sequence')
            ->groupBy('partner_user_id')
            ->pluck('latest_sequence', 'partner_user_id');

        foreach ($eligible as $partner) {
            $amount = $allocations[$partner['user_id']];
            if ($amount <= 0) {
                continue;
            }
            $sequence = (int) $latestSequences->get($partner['user_id'], 0) + 1;
            PartnerNetworkCommission::query()->firstOrCreate(
                [
                    'partner_user_id' => $partner['user_id'],
                    'invoice_id' => $invoice->id,
                    'product_id' => $productId,
                    'earning_sequence' => $sequence,
                ],
                [
                    'source_partner_user_id' => $sourcePartnerId,
                    'source_customer_id' => $invoice->customer_id,
                    'partner_level' => $partner['level'],
                    'level_weight' => $this->money($partner['weight_minor']),
                    'pool_rate' => $this->percent($poolRate),
                    'payment_base_amount' => $this->money($paymentBase),
                    'platform_amount' => $this->money($platformAmount),
                    'pool_amount' => $this->money($poolAmount),
                    'amount' => $this->money($amount),
                    'currency' => $invoice->currency,
                    'status' => 'earned',
                    'earned_at' => now(),
                ],
            );
        }
    }

    private function minor(string $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function percentage(int $amount, int $basisPoints): int
    {
        return intdiv($amount, 10000) * $basisPoints + intdiv(($amount % 10000) * $basisPoints, 10000);
    }

    private function money(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }

    private function percent(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }
}
