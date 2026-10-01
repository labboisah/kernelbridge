<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BillingSettingsController extends Controller
{
    public function edit()
    {
        return Inertia::render('Admin/BillingSettings', ['settings' => BillingSetting::findOrFail(1)]);
    }

    public function update(Request $request, AuditRecorder $audit)
    {
        $data = $request->validate(['vat_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100']]);
        DB::transaction(function () use ($request, $data, $audit) {
            $settings = BillingSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $before = $settings->vat_rate;
            $settings->update($data);
            $audit->record('billing.vat_rate_updated', $request->user(), ['before' => $before, 'after' => $settings->vat_rate]);
        });

        return back()->with('success', 'VAT rate updated for new invoices. Existing invoices retain their recorded rate.');
    }
}
