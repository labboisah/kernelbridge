<?php

namespace App\Domain\Reporting\Services;

use App\Models\Customer;
use App\Models\DemoRequest;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SupportTicket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ExecutiveDashboardService
{
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $catalogueVersion = Product::query()->max('updated_at')?->timestamp ?? 0;
        $key = 'executive-dashboard:v2:'.$catalogueVersion.':'.$from->toDateString().':'.$to->toDateString();

        return Cache::store(config('dashboard.cache_store'))->remember(
            $key,
            config('dashboard.cache_ttl_seconds'),
            fn (): array => $this->build($from->startOfDay(), $to->endOfDay()),
        );
    }

    private function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $period = fn ($query, string $column = 'created_at') => $query->whereBetween($column, [$from, $to]);
        $openInvoiceStatuses = ['issued', 'partially_paid', 'overdue'];
        $subscriptionStatuses = ['active', 'grace'];

        $quotationEligible = $period(Quotation::query())->whereIn('status', ['sent', 'accepted', 'rejected', 'converted'])->count();
        $quotationWon = $period(Quotation::query())->whereIn('status', ['accepted', 'converted'])->count();

        $metrics = [
            'totalCustomers' => Customer::query()->count(),
            'activeCustomers' => Customer::query()->where('status', 'active')->count(),
            'activeSubscriptions' => Subscription::query()->whereIn('status', $subscriptionStatuses)->count(),
            'trials' => Subscription::query()->where('status', 'trial')->count(),
            'activeLicenses' => SoftwareLicense::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'expiredLicenses' => SoftwareLicense::query()->where(fn ($q) => $q->where('status', 'expired')->orWhere('expires_at', '<=', now()))->count(),
            'outstandingInvoices' => Invoice::query()->whereIn('status', $openInvoiceStatuses)->count(),
            'pendingPayments' => Payment::query()->where('status', 'pending')->count(),
            'openSupportTickets' => SupportTicket::query()->whereIn('status', ['open', 'in_progress', 'waiting_customer'])->count(),
            'revenueThisMonth' => Payment::query()->where('status', 'verified')->whereBetween('payment_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            'revenueThisYear' => Payment::query()->where('status', 'verified')->whereBetween('payment_date', [now()->startOfYear(), now()->endOfYear()])->sum('amount'),
            'outstandingReceivables' => Invoice::query()->whereIn('status', $openInvoiceStatuses)->sum('balance'),
            'newLeads' => $period(Lead::query())->where('status', 'new')->count(),
            'demoRequests' => $period(DemoRequest::query())->count(),
            'demoCompleted' => $period(DemoRequest::query())->where('status', 'completed')->count(),
            'quotationConversion' => $quotationEligible > 0 ? round(($quotationWon / $quotationEligible) * 100, 1) : 0,
            'wonDeals' => $period(Lead::query())->where('status', 'won')->count(),
        ];

        // Kept for existing integrations while the executive UI uses the clearer names above.
        $metrics['pendingDemos'] = DemoRequest::query()->where('status', 'pending')->count();
        $metrics['scheduledDemos'] = DemoRequest::query()->where('status', 'scheduled')->count();
        $metrics['completedDemos'] = DemoRequest::query()->where('status', 'completed')->count();
        $leadCount = Lead::query()->count();
        $metrics['conversionRate'] = $leadCount > 0
            ? round((Lead::query()->where('status', 'won')->count() / $leadCount) * 100, 1)
            : 0;

        return [
            'metrics' => $metrics,
            'productMetrics' => $this->productMetrics(),
            'charts' => [
                'monthlyRevenue' => $this->monthlyRevenue($from, $to),
                'subscriptionsByProduct' => $this->subscriptionsByProduct(),
                'leadsByStatus' => $this->countByStatus(Lead::class, $from, $to),
                'invoiceStatus' => $this->countByStatus(Invoice::class, $from, $to),
                'customerGrowth' => $this->customerGrowth($from, $to),
            ],
        ];
    }

    private function productMetrics(): array
    {
        return Product::query()->where('products.status', 'active')
            ->leftJoin('subscriptions', function ($join): void {
                $join->on('subscriptions.product_id', '=', 'products.id')
                    ->whereIn('subscriptions.status', ['trial', 'active', 'grace']);
            })
            ->select('products.code', 'products.name', DB::raw('COUNT(DISTINCT subscriptions.customer_id) as customer_count'))
            ->groupBy('products.id', 'products.code', 'products.name', 'products.sort_order')
            ->orderBy('products.sort_order')->orderBy('products.name')->get()
            ->map(fn ($product): array => ['code' => $product->code, 'name' => $product->name, 'customers' => (int) $product->customer_count])->all();
    }

    private function subscriptionsByProduct(): array
    {
        return Product::query()->leftJoin('subscriptions', function ($join): void {
            $join->on('subscriptions.product_id', '=', 'products.id')->whereIn('subscriptions.status', ['trial', 'active', 'grace']);
        })->where('products.status', 'active')
            ->select('products.name as label', DB::raw('COUNT(subscriptions.id) as value'))
            ->groupBy('products.id', 'products.name', 'products.sort_order')->orderBy('products.sort_order')->orderBy('products.name')->get()
            ->map(fn ($row): array => ['label' => $row->label, 'value' => (int) $row->value])->all();
    }

    private function countByStatus(string $model, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $model::query()->whereBetween('created_at', [$from, $to])->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')->orderBy('status')->get()->map(fn ($row): array => [
                'label' => str($row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status)->replace('_', ' ')->title()->toString(),
                'value' => (int) $row->aggregate,
            ])->all();
    }

    private function monthlyRevenue(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->monthBuckets($from, $to);
        Payment::query()->where('status', 'verified')->whereBetween('payment_date', [$from, $to])
            ->get(['payment_date', 'amount'])->each(function (Payment $payment) use (&$months): void {
                $key = $payment->payment_date->format('Y-m');
                $months[$key] = ($months[$key] ?? 0) + (float) $payment->amount;
            });

        return $this->series($months, true);
    }

    private function customerGrowth(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->monthBuckets($from, $to);
        $running = Customer::query()->where('created_at', '<', $from)->count();
        Customer::query()->whereBetween('created_at', [$from, $to])->get(['created_at'])->each(function (Customer $customer) use (&$months): void {
            $key = $customer->created_at->format('Y-m');
            $months[$key] = ($months[$key] ?? 0) + 1;
        });
        foreach ($months as $key => $value) {
            $running += $value;
            $months[$key] = $running;
        }

        return $this->series($months);
    }

    private function monthBuckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $months[$month->format('Y-m')] = 0;
        }

        return $months;
    }

    private function series(array $months, bool $money = false): array
    {
        return collect($months)->map(fn ($value, string $month): array => [
            'label' => CarbonImmutable::createFromFormat('Y-m', $month)->format('M Y'),
            'value' => $money ? round((float) $value, 2) : (int) $value,
        ])->values()->all();
    }
}
