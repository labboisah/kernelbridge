<?php

namespace App\Providers;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Observers\SecurityAuditObserver;
use App\Domain\Audit\Services\DatabaseAuditRecorder;
use App\Domain\Notifications\Services\BusinessLifecycleNotifications;
use App\Domain\Security\Contracts\FileScanner;
use App\Domain\Security\Services\NullFileScanner;
use App\Enums\UserRole;
use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);
        $this->app->bind(FileScanner::class, NullFileScanner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Vite::prefetch(concurrency: 3);

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Gate::before(fn ($user): ?bool => $user->hasRole(UserRole::SuperAdmin->value) ? true : null);
        app(BusinessLifecycleNotifications::class)->register();
        foreach ([Customer::class, Product::class, Plan::class, Quotation::class, Invoice::class, Payment::class, Subscription::class, SoftwareLicense::class, ApiClient::class, ApiClientCredential::class, Role::class, Permission::class] as $model) {
            $model::observe(SecurityAuditObserver::class);
        }
        Event::listen(Login::class, fn (Login $event) => app(AuditRecorder::class)->record('authentication.login', $event->user, ['guard' => $event->guard], $event->user));
        Event::listen(Logout::class, fn (Logout $event) => app(AuditRecorder::class)->record('authentication.logout', $event->user, ['guard' => $event->guard], $event->user));
        Event::listen(Failed::class, fn (Failed $event) => app(AuditRecorder::class)->record('authentication.failed', $event->user, ['guard' => $event->guard, 'email' => $event->credentials['email'] ?? null], $event->user));
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => app(AuditRecorder::class)->record('authentication.password_reset', $event->user, entity: $event->user));
        Event::listen(RoleAttachedEvent::class, fn (RoleAttachedEvent $event) => app(AuditRecorder::class)->record('permission.role_attached', auth()->user(), ['roles' => $event->rolesOrIds], $event->model));
        Event::listen(RoleDetachedEvent::class, fn (RoleDetachedEvent $event) => app(AuditRecorder::class)->record('permission.role_detached', auth()->user(), ['roles' => $event->rolesOrIds], $event->model));
        Event::listen(PermissionAttachedEvent::class, fn (PermissionAttachedEvent $event) => app(AuditRecorder::class)->record('permission.attached', auth()->user(), ['permissions' => $event->permissionsOrIds], $event->model));
        Event::listen(PermissionDetachedEvent::class, fn (PermissionDetachedEvent $event) => app(AuditRecorder::class)->record('permission.detached', auth()->user(), ['permissions' => $event->permissionsOrIds], $event->model));
    }
}
