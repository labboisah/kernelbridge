<?php

namespace App\Http\Controllers\PublicSite;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;
use Throwable;

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
        $application = PartnerApplication::where('user_id', $request->user()->id)->where('type', $type)->first();
        if ($application) {
            $application->setAttribute('has_photo', $application->photo_path !== null);
        }

        return Inertia::render('Public/PartnerOnboarding', [
            'type' => $type, 'category' => $this->category($type),
            'application' => $application, 'questionnaire' => config('partner_questionnaire'),
        ]);
    }

    public function photo(Request $request, PartnerApplication $application)
    {
        abort_unless($application->user_id === $request->user()->id || $request->user()->hasAnyRole(['admin', 'super_admin']), 403);
        abort_unless($application->photo_path && Storage::disk('local')->exists($application->photo_path), 404);

        return Storage::disk('local')->response($application->photo_path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function save(Request $request, string $type)
    {
        $this->category($type);
        $submit = $request->input('action') === 'submit';
        $guidedAnswers = null;
        if ($request->has('guided_answers')) {
            $request->validate(['guided_answers' => ['required', 'string', 'json', 'max:20000']]);
            $decoded = json_decode($request->input('guided_answers'), true);
            $questions = collect(config('partner_questionnaire'))->flatMap(fn ($section) => $section['questions']);
            $keys = $questions->pluck('key')->all();
            $rules = ['answers' => ['required', 'array:'.implode(',', $keys)]];
            foreach ($questions as $question) {
                $key = 'answers.'.$question['key'];
                if ($question['type'] === 'multiple') {
                    $rules[$key] = ['sometimes', 'array', 'max:'.count($question['options'])];
                    $rules[$key.'.*'] = ['required', 'string', 'distinct', Rule::in($question['options'])];
                } else {
                    $rules[$key] = ['sometimes', 'nullable', 'string', Rule::in($question['options'])];
                }
            }
            $guidedAnswers = Validator::make(['answers' => $decoded], $rules)->validate()['answers'];
        }
        $data = $request->validate([
            'action' => ['required', Rule::in(['save', 'submit'])],
            'organisation' => ['nullable', 'string', 'max:255'],
            'phone' => [$submit ? 'required' : 'nullable', 'string', 'max:30'],
            'location' => [$submit ? 'required' : 'nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:500'],
            'details' => [$submit ? 'required' : 'nullable', 'string', 'max:5000'],
            'background_knowledge' => ['nullable', 'string', 'max:2000'],
            'digital_skills' => ['nullable', 'string', 'max:2000'],
            'installation_experience' => ['nullable', 'string', 'max:2000'],
            'networking_experience' => ['nullable', 'string', 'max:2000'],
            'business_skills' => ['nullable', 'string', 'max:2000'],
            'management_skills' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'consent' => [$submit ? 'accepted' : 'nullable'],
        ]);
        $newPhoto = null;
        if ($request->hasFile('photo')) {
            $newPhoto = $request->file('photo')->store('partner-photos', 'local');
            if ($newPhoto === false) {
                throw new RuntimeException('Unable to store the partner photo.');
            }
        }
        $oldPhoto = null;
        try {
            DB::transaction(function () use ($request, $type, $data, $submit, $newPhoto, $guidedAnswers, &$oldPhoto) {
                // Serialise submissions for this account, including the first draft.
                $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $application = PartnerApplication::where('user_id', $request->user()->id)->where('type', $type)->first()
                    ?? (new PartnerApplication)->forceFill(['user_id' => $request->user()->id, 'type' => $type]);
                abort_unless(! $application->exists || $application->status === 'draft', 409, 'This application has already been submitted.');
                $application->fill(collect($data)->only(['organisation', 'phone', 'location', 'website', 'details', 'background_knowledge', 'digital_skills', 'installation_experience', 'networking_experience', 'business_skills', 'management_skills'])->all());
                if ($guidedAnswers !== null) {
                    $application->guided_answers = $guidedAnswers;
                }
                if ($newPhoto) {
                    $oldPhoto = $application->photo_path;
                    $application->photo_path = $newPhoto;
                }
                $application->status = $submit ? 'submitted' : 'draft';
                if ($submit) {
                    $application->submitted_at = now();
                    $application->consented_at = now();
                }
                $application->save();
                $request->user()->assignRole(UserRole::forPartnerType($type));
            });
        } catch (Throwable $exception) {
            if ($newPhoto && ! Storage::disk('local')->delete($newPhoto)) {
                report(new RuntimeException('Unable to clean up an unused partner photo.'));
            }
            throw $exception;
        }
        if ($oldPhoto && ! Storage::disk('local')->delete($oldPhoto)) {
            report(new RuntimeException('Unable to remove a replaced partner photo.'));
        }

        return redirect()->route('partners.onboarding', $type)->with('success', $submit ? 'Application submitted. You can check its status here.' : 'Progress saved.');
    }
}
