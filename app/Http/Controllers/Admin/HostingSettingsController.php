<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HostingSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class HostingSettingsController extends Controller
{
    public function edit()
    {
        return Inertia::render('Admin/HostingSettings', ['settings' => HostingSetting::findOrFail(1)]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'currency' => ['required', 'in:NGN,USD,GBP,EUR,GHS,KES,ZAR'], 'monthly_price' => ['required', 'numeric', 'min:0.01', 'max:999999999', 'decimal:0,2']]);
        HostingSetting::findOrFail(1)->update($data);

        return back()->with('success', 'Hosting pricing updated. Existing invoices and contracted hosting rates are unchanged.');
    }
}
