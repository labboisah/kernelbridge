<?php

namespace App\Http\Controllers\PublicSite;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PartnerController extends Controller
{
    private function category(string $type): array
    {
        abort_unless(array_key_exists($type, config('partners')), 404);

        return config('partners')[$type];
    }

    public function index()
    {
        return Inertia::render('Public/Partners', ['types' => config('partners')]);
    }

    public function start(Request $request, string $type)
    {
        $this->category($type);
        $url = route('partners.onboarding', $type);
        if ($request->user()) {
            return redirect($url);
        }
        $request->session()->put('url.intended', $url);

        return Inertia::render('Partner/Register', ['type' => $type, 'category' => $this->category($type)]);
    }

    public function edit(Request $request, string $type)
    {
        return Inertia::render('Public/PartnerOnboarding', [
            'type' => $type, 'category' => $this->category($type),
            'application' => PartnerApplication::where('user_id', $request->user()->id)->where('type', $type)->first(),
        ]);
    }

    public function save(Request $request, string $type)
    {
        $this->category($type);
        $submit = $request->input('action') === 'submit';
        $data = $request->validate([
            'action' => ['required', Rule::in(['save', 'submit'])],
            'organisation' => ['nullable', 'string', 'max:255'],
            'phone' => [$submit ? 'required' : 'nullable', 'string', 'max:30'],
            'location' => [$submit ? 'required' : 'nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:500'],
            'details' => [$submit ? 'required' : 'nullable', 'string', 'max:5000'],
            'consent' => [$submit ? 'accepted' : 'nullable'],
        ]);
        DB::transaction(function () use ($request, $type, $data, $submit) {
            // Serialise submissions for this account, including the first draft.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $application = PartnerApplication::where('user_id', $request->user()->id)->where('type', $type)->first()
                ?? (new PartnerApplication)->forceFill(['user_id' => $request->user()->id, 'type' => $type]);
            abort_unless(! $application->exists || $application->status === 'draft', 409, 'This application has already been submitted.');
            $application->fill(collect($data)->only(['organisation', 'phone', 'location', 'website', 'details'])->all());
            $application->status = $submit ? 'submitted' : 'draft';
            if ($submit) {
                $application->submitted_at = now();
                $application->consented_at = now();
            }
            $application->save();
            $request->user()->assignRole(UserRole::forPartnerType($type));
        });

        return redirect()->route('partners.onboarding', $type)->with('success', $submit ? 'Application submitted. You can check its status here.' : 'Progress saved.');
    }
}
