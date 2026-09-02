<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Demo\Services\DemoProfileProvisioner;
use App\Http\Controllers\Controller;
use App\Models\DemoEvent;
use App\Models\DemoProfile;
use App\Models\DemoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DemoModeController extends Controller
{
    private const FLAGS = ['allow_create', 'allow_edit', 'allow_delete', 'allow_export', 'allow_email', 'allow_sms', 'allow_payment', 'allow_configuration', 'allow_user_management'];

    public function index(DemoProfileProvisioner $profiles): Response
    {
        $this->authorize('demo.analytics');
        $profiles = $profiles->provisionActiveProducts();
        $byProduct = DemoSession::query()->join('products', 'products.id', '=', 'demo_sessions.product_id')->selectRaw('products.code, products.name, count(*) as sessions')->selectRaw("sum(case when exists (select 1 from demo_events where demo_events.demo_session_id = demo_sessions.id and demo_events.event_type = 'request_full_version') then 1 else 0 end) as full_version_requests")->groupBy('products.id', 'products.code', 'products.name')->get();

        return Inertia::render('Admin/DemoMode/Index', ['profiles' => $profiles, 'metrics' => ['sessions' => DemoSession::query()->count(), 'active_sessions' => DemoSession::query()->whereNull('ended_at')->where('last_activity_at', '>=', now()->subMinutes(30))->count(), 'page_views' => DemoEvent::query()->where('event_type', 'page_view')->count(), 'features_used' => DemoEvent::query()->where('event_type', 'feature_used')->count(), 'cta_clicks' => DemoEvent::query()->where('event_type', 'cta_clicked')->count(), 'full_version_requests' => DemoEvent::query()->where('event_type', 'request_full_version')->count()], 'byProduct' => $byProduct, 'topPages' => DemoEvent::query()->whereNotNull('page')->select('page', DB::raw('count(*) as uses'))->groupBy('page')->orderByDesc('uses')->limit(10)->get(), 'topFeatures' => DemoEvent::query()->whereNotNull('feature')->select('feature', DB::raw('count(*) as uses'))->groupBy('feature')->orderByDesc('uses')->limit(10)->get()]);
    }

    public function update(Request $request, DemoProfile $profile, AuditRecorder $audit): RedirectResponse
    {
        $this->authorize('demo.configure');
        $rules = ['name' => ['required', 'string', 'max:255'], 'public_demo_url' => ['nullable', 'url', 'max:2000'], 'is_active' => ['required', 'boolean'], 'requires_access_token' => ['required', 'boolean'], 'protected_roles' => ['nullable', 'array'], 'protected_roles.*' => ['string', 'max:100'], 'protected_accounts' => ['nullable', 'array'], 'protected_accounts.*' => ['string', 'max:255'], 'reset_enabled' => ['required', 'boolean'], 'reset_schedule' => ['nullable', 'string', 'max:100'], 'reset_instructions' => ['nullable', 'string', 'max:10000'], 'next_reset_at' => ['nullable', 'date'], 'configuration' => ['nullable', 'array']];
        foreach (self::FLAGS as $flag) {
            $rules[$flag] = ['required', 'boolean'];
        }$data = $request->validate($rules);
        DB::transaction(function () use ($profile, $data, $request, $audit) {
            $resetChanged = $profile->reset_instructions !== ($data['reset_instructions'] ?? null) || $profile->reset_schedule !== ($data['reset_schedule'] ?? null);
            $profile->update([...$data, 'config_version' => $profile->config_version + 1, 'reset_instruction_version' => $resetChanged ? $profile->reset_instruction_version + 1 : $profile->reset_instruction_version]);
            $audit->record('demo_profile.updated', $request->user(), ['profile_uuid' => $profile->uuid, 'config_version' => $profile->config_version]);
        });

        return back()->with('success', 'Demo configuration published.');
    }
}
