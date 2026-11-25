<?php

namespace App\Http\Middleware;

use App\Models\PartnerApplication;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
            'auth' => Inertia::always(function () use ($request): array {
                $user = $request->user();
                if ($user) {
                    foreach (['roles', 'permissions', 'temporaryPermissions'] as $relation) {
                        $user->unsetRelation($relation);
                    }
                }

                return [
                    'user' => $user,
                    'admin_photo_url' => fn (): ?string => $user?->admin_photo_path
                        && $user->can('admin.profile.manage')
                            ? route('profile.photo', ['v' => substr(hash('sha256', $user->admin_photo_path), 0, 16)])
                            : null,
                    'partner_photo_url' => function () use ($user): ?string {
                        if (! $user) {
                            return null;
                        }
                        $application = PartnerApplication::query()->where('user_id', $user->id)
                            ->whereNotNull('photo_path')->latest('updated_at')->orderByDesc('id')->first(['id']);

                        return $application ? route('partners.photo', $application) : null;
                    },
                    'is_partner' => fn (): bool => $user?->isPartner() ?? false,
                    'approved_agent' => fn (): bool => $user?->isApprovedAgent() ?? false,
                    'partner_category' => fn (): ?string => $user?->isApprovedAgent()
                        ? PartnerApplication::where('user_id', $user->id)->where('type', 'sales_agent')->value('partner_category')
                        : null,
                    'partner_applications' => fn (): array => $user?->isPartner()
                        ? PartnerApplication::query()->where('user_id', $user->id)
                            ->get(['type', 'status'])
                            ->map(fn (PartnerApplication $application): array => [
                                'type' => $application->type,
                                'label' => config("partners.{$application->type}.label"),
                                'status' => $application->status,
                            ])
                            ->all()
                        : [],
                    'roles' => fn (): array => $user?->getRoleNames()->all() ?? [],
                    'permissions' => fn (): array => $user
                        ? $user->getAllPermissions()
                            ->merge($user->temporaryPermissions)
                            ->pluck('name')->unique()->values()->all()
                        : [],
                    'unread_notifications' => fn (): int => $user?->unreadNotifications()->count() ?? 0,
                ];
            }),
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
                'activation_code' => fn (): ?array => $request->session()->pull('activation_code'),
            ],
            'app' => ['url' => config('app.url')],
            'billing' => fn (): array => ['vat_rate' => \App\Models\BillingSetting::vatRate()],
        ];
    }
}
