<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Partners\PartnerQualificationService;
use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\PartnerCommission;
use App\Models\PartnerNetworkCommission;
use App\Models\Product;
use App\Models\ProductCommissionAccrual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PartnerCommissionController extends Controller
{
    private const FIELDS = ['vat_commission_rate', 'developer_commission_rate', 'platform_commission_rate', 'staff_commission_rate', 'partner_commission_rate', 'organization_partner_commission_rate'];
    private const NETWORK_FIELDS = [
        'partner_network_sharing_enabled',
        'partner_network_min_active_clients',
        'partner_network_pool_rate',
        'partner_network_qualified_weight',
        'partner_network_premium_weight',
        'partner_network_elite_weight',
    ];

    public function index()
    {
        $totals = ProductCommissionAccrual::where('status', 'earned')->select('product_id', 'currency')
            ->selectRaw('SUM(vat_amount) as vat_amount, SUM(developer_amount + platform_amount + staff_amount + agent_amount) as total')
            ->groupBy('product_id', 'currency')->get();
        $legacy = PartnerCommission::where('status', 'earned')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('product_commission_accruals')->whereColumn('product_commission_accruals.invoice_id', 'partner_commissions.invoice_id')->whereColumn('product_commission_accruals.product_id', 'partner_commissions.product_id'))
            ->select('product_id', 'currency')->selectRaw('SUM(amount) as total')->groupBy('product_id', 'currency')->get();
        $generated = $totals->concat($legacy)->groupBy('product_id')->map(fn ($rows) => $rows->groupBy('currency')->map(fn ($currencyRows, $currency) => [
            'currency' => $currency, 'total' => number_format($currencyRows->sum('total'), 2, '.', ''),
            'vat' => number_format($currencyRows->sum('vat_amount'), 2, '.', ''),
        ])->values());
        $networkTotals = PartnerNetworkCommission::query()
            ->select('currency')
            ->selectRaw("SUM(CASE WHEN status = 'earned' THEN amount ELSE 0 END) as liability")
            ->selectRaw('SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END) as paid_out')
            ->selectRaw("SUM(CASE WHEN status = 'reversed' THEN amount ELSE 0 END) as reversed")
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'liability' => number_format((float) $row->liability, 2, '.', ''),
                'paid_out' => number_format((float) $row->paid_out, 2, '.', ''),
                'reversed' => number_format((float) $row->reversed, 2, '.', ''),
            ]);

        return Inertia::render('Admin/Partners/Commissions', [
            'pointValue' => BillingSetting::findOrFail(1)->partner_point_value,
            'networkSettings' => BillingSetting::findOrFail(1)->only(self::NETWORK_FIELDS),
            'networkTotals' => $networkTotals,
            'networkPartners' => app(PartnerQualificationService::class)->approvedPartnerPage(),
            'networkEntries' => PartnerNetworkCommission::query()
                ->with(['partner:id,name,email', 'sourcePartner:id,name', 'customer:id,name', 'invoice:id,invoice_number', 'product:id,name'])
                ->latest('earned_at')
                ->paginate(15, ['*'], 'network_page'),
            'products' => Product::orderBy('name')->get(['id', 'slug', 'name', ...self::FIELDS])->map(fn ($product) => [
                ...$product->toArray(),
                'generated' => $generated->get($product->id, collect())->all(),
            ]),
        ]);
    }

    public function updatePointValue(Request $request)
    {
        $data = $request->validate([
            'partner_point_value' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000000'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $settings = BillingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $before = $settings->partner_point_value;
            $settings->update($data);
            app(AuditRecorder::class)->record('partner.point_value_updated', $request->user(), [
                'before' => $before,
                'after' => $settings->partner_point_value,
            ]);
        });

        return back()->with('success', 'Partner point value updated. Existing redemptions keep their recorded value.');
    }

    public function updateNetworkPolicy(Request $request)
    {
        $data = $request->validate([
            'partner_network_sharing_enabled' => ['required', 'boolean'],
            'partner_network_min_active_clients' => ['required', 'integer', 'min:1', 'max:100000'],
            'partner_network_pool_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'partner_network_qualified_weight' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000'],
            'partner_network_premium_weight' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000'],
            'partner_network_elite_weight' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $settings = BillingSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $before = $settings->only(self::NETWORK_FIELDS);
            $settings->update($data);
            app(AuditRecorder::class)->record('partner.network_policy_updated', $request->user(), [
                'before' => $before,
                'after' => $settings->only(self::NETWORK_FIELDS),
            ]);
        });

        return back()->with('success', 'Partner network sharing policy updated.');
    }

    public function markNetworkCommissionPaid(Request $request, PartnerNetworkCommission $commission)
    {
        DB::transaction(function () use ($request, $commission): void {
            $commission = PartnerNetworkCommission::query()->whereKey($commission->id)->lockForUpdate()->firstOrFail();
            abort_unless($commission->status === 'earned', 409, 'Only earned network commissions can be marked as paid.');
            $commission->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by' => $request->user()->id,
            ])->save();
            app(AuditRecorder::class)->record('partner.network_commission_paid', $request->user(), [
                'commission_id' => $commission->id,
                'partner_user_id' => $commission->partner_user_id,
                'amount' => $commission->amount,
                'currency' => $commission->currency,
            ], $commission);
        });

        return back()->with('success', 'Network commission payout recorded.');
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate(array_fill_keys(self::FIELDS, ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100']));
        $basisPoints = array_sum(array_map(fn ($rate) => (int) round((float) $data[$rate] * 100), [
            'vat_commission_rate',
            'developer_commission_rate',
            'platform_commission_rate',
            'staff_commission_rate',
            'partner_commission_rate',
        ]));
        if ($basisPoints !== 10000) {
            throw ValidationException::withMessages(['allocation' => 'VAT, developer, platform, staff and agent shares must total exactly 100%.']);
        }
        $organizationPlatformRate = (int) round((float) $data['platform_commission_rate'] * 100)
            + (int) round((float) $data['partner_commission_rate'] * 100)
            - (int) round((float) $data['organization_partner_commission_rate'] * 100);
        if ($organizationPlatformRate < 0) {
            throw ValidationException::withMessages([
                'organization_partner_commission_rate' => 'Organization commission cannot exceed the available platform share plus the individual partner share.',
            ]);
        }
        DB::transaction(function () use ($request, $product, $data) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $product->only(self::FIELDS);
            $product->forceFill($data)->save();
            app(AuditRecorder::class)->record('partner.commission_rate_updated', $request->user(), ['product_uuid' => $product->uuid, 'before' => $before, 'after' => $product->only(self::FIELDS)]);
        });

        return back()->with('success', 'Product commission shares saved. Existing earned commissions retain their recorded rate.');
    }
}
