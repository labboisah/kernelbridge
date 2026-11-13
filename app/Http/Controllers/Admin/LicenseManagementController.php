<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\Services\InstallationActivationService;
use App\Http\Controllers\Controller;
use App\Models\LicenseActivationCode;
use App\Models\LicenseInstallation;
use App\Models\LicensePlatformAccountLink;
use App\Models\SoftwareLicense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LicenseManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,suspended,expired,revoked'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $licenses = SoftwareLicense::query()
            ->with(['product:id,name,code,licensing_mode', 'subscription:id,customer_id,plan_id,subscription_number,status', 'subscription.customer:id,name,email', 'subscription.plan:id,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('uuid', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($product) => $product->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('subscription.customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            }))
            ->when(($filters['status'] ?? null) === 'expired', fn ($query) => $query->where(function ($query): void {
                $query->where('status', 'expired')
                    ->orWhere(fn ($query) => $query->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<=', now()));
            }))
            ->when(isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'expired', fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SoftwareLicense $license): array => [
                'uuid' => $license->uuid,
                'status' => $license->status === 'active' && $license->expires_at?->isPast() ? 'expired' : $license->status,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'product' => ['name' => $license->product->name, 'code' => $license->product->code],
                'mode' => $license->product->licensing_mode,
                'customer' => ['name' => $license->subscription->customer->name, 'email' => $license->subscription->customer->email],
                'plan' => $license->subscription->plan?->name,
                'subscription_status' => $license->subscription->status->value,
                'activation_codes_count' => $license->activationCodes()->count(),
                'installations_count' => $license->installations()->count(),
            ]);

        return Inertia::render('Admin/Licensing/Index', ['licenses' => $licenses, 'filters' => $filters]);
    }

    public function show(SoftwareLicense $license): Response
    {
        $license->load([
            'product:id,name,code,licensing_mode',
            'subscription:id,customer_id,plan_id,subscription_number,status',
            'subscription.customer:id,name,email',
            'subscription.plan:id,name',
        ]);

        $activationCodes = $license->activationCodes()->latest()->get()->map(fn (LicenseActivationCode $code): array => [
            'uuid' => $code->uuid,
            'kind' => $code->bound_installation_uuid ? 'installation' : 'activation',
            'status' => $code->status === 'issued' && $code->expires_at?->isPast() ? 'expired' : $code->status,
            'bound_installation_uuid' => $code->bound_installation_uuid,
            'device_name' => $code->device_name,
            'entitlement_source' => $code->entitlement_source,
            'issued_at' => $code->created_at?->toIso8601String(),
            'expires_at' => $code->expires_at?->toIso8601String(),
            'redeemed_at' => $code->redeemed_at?->toIso8601String(),
            'redeemed_installation_uuid' => $code->redeemed_installation_uuid,
            'can_revoke' => $code->status === 'issued' && $code->expires_at?->isFuture(),
        ]);
        $installations = $license->installations()->latest()->get()->map(fn (LicenseInstallation $installation): array => [
            'uuid' => $installation->uuid,
            'installation_uuid' => $installation->installation_uuid,
            'business_uuid' => $installation->business_uuid,
            'device_name' => $installation->device_name,
            'runtime_type' => $installation->runtime_type,
            'status' => $installation->status,
            'activated_at' => $installation->activated_at?->toIso8601String(),
            'last_verified_at' => $installation->last_verified_at?->toIso8601String(),
            'expires_at' => $installation->expires_at?->toIso8601String(),
            'can_revoke' => $installation->status === 'active',
        ]);
        $links = $license->platformAccountLinks()
            ->where('status', 'approved')
            ->latest()
            ->get()
            ->map(fn (LicensePlatformAccountLink $link): array => [
                'uuid' => $link->uuid,
                'business_uuid' => $link->business_uuid,
                'approved_at' => $link->approved_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Licensing/Show', [
            'license' => [
                'uuid' => $license->uuid,
                'status' => $license->status === 'active' && $license->expires_at?->isPast() ? 'expired' : $license->status,
                'expires_at' => $license->expires_at?->toIso8601String(),
                'max_activations' => $license->max_activations,
                'product' => ['name' => $license->product->name, 'code' => $license->product->code, 'mode' => $license->product->licensing_mode],
                'customer' => ['name' => $license->subscription->customer->name, 'email' => $license->subscription->customer->email],
                'subscription' => [
                    'uuid' => $license->subscription->uuid,
                    'number' => $license->subscription->subscription_number,
                    'status' => $license->subscription->status->value,
                    'plan' => $license->subscription->plan?->name,
                ],
            ],
            'activationCodes' => $activationCodes,
            'installations' => $installations,
            'platformLinks' => $links,
        ]);
    }

    public function issueCode(Request $request, SoftwareLicense $license, InstallationActivationService $activation): RedirectResponse
    {
        $data = $request->validate([
            'installation_uuid' => ['nullable', 'uuid', 'required_with:platform_account_link_uuid'],
            'platform_account_link_uuid' => ['nullable', 'uuid', 'required_with:installation_uuid'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        $mode = $license->product()->value('licensing_mode');
        $link = null;
        if (filled($data['platform_account_link_uuid'] ?? null)) {
            if (! in_array($mode, ['platform_account', 'hybrid'], true)) {
                throw ValidationException::withMessages([
                    'platform_account_link_uuid' => 'This product does not issue platform-bound installation codes.',
                ]);
            }

            $link = LicensePlatformAccountLink::query()
                ->where('uuid', $data['platform_account_link_uuid'])
                ->where('software_license_id', $license->id)
                ->where('product_id', $license->product_id)
                ->where('status', 'approved')
                ->first();

            if (! $link) {
                throw ValidationException::withMessages([
                    'platform_account_link_uuid' => 'Select an approved platform account link for this license.',
                ]);
            }
        } elseif ($mode === 'platform_account') {
            throw ValidationException::withMessages([
                'platform_account_link_uuid' => 'An approved platform account link and installation UUID are required.',
            ]);
        }

        $issued = $activation->issueCode(
            $license,
            $request->user(),
            boundInstallationUuid: $data['installation_uuid'] ?? null,
            platformLink: $link,
            deviceName: $data['device_name'] ?? null,
        );

        return redirect()->route('admin.licenses.show', $license->uuid)->with('activation_code', [
            'license_uuid' => $license->uuid,
            'kind' => $issued['activation_code']->bound_installation_uuid ? 'installation' : 'activation',
            'installation_uuid' => $issued['activation_code']->bound_installation_uuid,
            'code' => $issued['code'],
            'expires_at' => $issued['activation_code']->expires_at->toIso8601String(),
        ])->with('success', 'Code issued. Copy it now; it will only be shown once.');
    }

    public function revokeCode(Request $request, SoftwareLicense $license, LicenseActivationCode $activationCode, InstallationActivationService $activation): RedirectResponse
    {
        abort_unless($activationCode->software_license_id === $license->id, 404);
        $activation->revokeCodeForAdmin($activationCode, $request->user());

        return back()->with('success', 'Unused code revoked and its reserved seat released.');
    }

    public function revokeInstallation(Request $request, SoftwareLicense $license, LicenseInstallation $installation, InstallationActivationService $activation): RedirectResponse
    {
        abort_unless($installation->software_license_id === $license->id, 404);
        $result = $activation->revokeForAdmin($installation, $request->user());
        $message = $result['status'] === 'revoked'
            ? 'Installation revoked and its seat released.'
            : 'This installation is already inactive.';

        return back()->with('success', $message);
    }
}
