<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PartnerController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Partners/Index', ['applications' => PartnerApplication::with('user:id,name,email')->where('status', '!=', 'draft')->latest('submitted_at')->paginate(20), 'types' => config('partners')]);
    }

    public function update(Request $request, PartnerApplication $application)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'declined'])], 'review_note' => ['required', 'string', 'max:2000']]);
        $changed = PartnerApplication::whereKey($application->id)->where('status', 'submitted')->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        abort_unless($changed, 409, 'Only submitted applications can be reviewed.');

        return back()->with('success', 'Partner application reviewed.');
    }
}
