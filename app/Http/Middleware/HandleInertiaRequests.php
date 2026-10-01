<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'is_partner' => fn (): bool => $request->user()?->isPartner() ?? false,
                'approved_agent' => fn (): bool => $request->user()?->isApprovedAgent() ?? false,
                'roles' => fn (): array => $request->user()?->getRoleNames()->all() ?? [],
                'permissions' => fn (): array => $request->user()?->getAllPermissions()->pluck('name')->all() ?? [],
            ],
            'flash' => ['success' => fn (): ?string => $request->session()->get('success'), 'error' => fn (): ?string => $request->session()->get('error')],
            'app' => ['url' => config('app.url')],
            'billing' => fn (): array => ['vat_rate' => \App\Models\BillingSetting::vatRate()],
        ];
    }
}
