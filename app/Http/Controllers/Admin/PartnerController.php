<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Models\PartnerBankDetails;
use App\Notifications\PartnerApplicationApproved;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PartnerController extends Controller
{
    public function index()
    {
        $applications = PartnerApplication::with('user:id,name,email')->where('status', '!=', 'draft')->latest('submitted_at')->paginate(20);
        $bankDetails = PartnerBankDetails::whereIn('user_id', $applications->pluck('user_id'))->get()->keyBy('user_id');
        $applications->getCollection()->each(function (PartnerApplication $application) use ($bankDetails): void {
            $bank = $bankDetails->get($application->user_id);
            $application->setAttribute('bank_details', $bank ? [
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'account_number' => $bank->account_number,
            ] : null);
            $application->setAttribute('has_photo', $application->photo_path !== null);
        });

        return Inertia::render('Admin/Partners/Index', ['applications' => $applications, 'types' => config('partners'), 'questionnaire' => config('partner_questionnaire')])
            ->toResponse(request())->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, PartnerApplication $application)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'declined'])], 'review_note' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($application, $request, $data): void {
            $changed = PartnerApplication::whereKey($application->id)->where('status', 'submitted')->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            abort_unless($changed, 409, 'Only submitted applications can be reviewed.');

            if ($data['status'] === 'approved') {
                $application->user()->firstOrFail()->notify(new PartnerApplicationApproved($application->type));
            }
        });

        return back()->with('success', 'Partner application reviewed.');
    }
}
