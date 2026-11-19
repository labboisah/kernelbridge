<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\PartnerBankDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BankDetailsController extends Controller
{
    private function authorizePartner(Request $request): void
    {
        abort_unless(PartnerApplication::where('user_id', $request->user()->id)->where('status', 'approved')->exists(), 403);
    }

    public function edit(Request $request)
    {
        $this->authorizePartner($request);
        $bank = PartnerBankDetails::where('user_id', $request->user()->id)->first();

        return Inertia::render('Partner/BankDetails', ['bankDetails' => $bank ? [
            'bank_name' => $bank->bank_name,
            'account_name' => $bank->account_name,
            'account_number' => $bank->account_number,
        ] : null])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request)
    {
        $this->authorizePartner($request);
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $bank = PartnerBankDetails::where('user_id', $request->user()->id)->first()
                ?? (new PartnerBankDetails)->forceFill(['user_id' => $request->user()->id]);
            $bank->fill($data)->save();
        });

        return redirect()->route('partner.bank.edit')->with('success', 'Bank details saved.');
    }
}
