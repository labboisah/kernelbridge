<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\PartnerCommission;
use App\Models\Product;
use App\Models\ProductCommissionAccrual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PartnerCommissionController extends Controller
{
    private const FIELDS = ['vat_commission_rate', 'developer_commission_rate', 'platform_commission_rate', 'staff_commission_rate', 'partner_commission_rate'];

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

        return Inertia::render('Admin/Partners/Commissions', ['products' => Product::orderBy('name')->get(['id', 'slug', 'name', ...self::FIELDS])->map(fn ($product) => [
            ...$product->toArray(), 'generated' => $generated->get($product->id, collect())->all(),
        ])]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate(array_fill_keys(self::FIELDS, ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100']));
        $basisPoints = array_sum(array_map(fn ($rate) => (int) round((float) $rate * 100), $data));
        if ($basisPoints !== 10000) {
            throw ValidationException::withMessages(['allocation' => 'VAT, developer, platform, staff and agent shares must total exactly 100%.']);
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
