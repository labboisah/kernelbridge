<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(UserRole::names())],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
        ]);
        $users = User::query()->with('roles:id,name')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')->paginate(25)->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => UserRole::names(),
            'currentUserPublicId' => $request->user()->public_id,
        ]);
    }

    public function deleted(Request $request): Response
    {
        $this->authorizeSuperAdmin($request);
        $users = User::onlyTrashed()->with('roles:id,name')
            ->orderByDesc('deleted_at')->orderByDesc('id')
            ->paginate(25);

        return Inertia::render('Admin/Users/Deleted', [
            'users' => $users,
        ]);
    }

    public function update(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(UserRole::names())],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);
        $roles = $data['roles'];
        $this->assertCanChangeAccount($request->user(), $user, $roles, $data['status']);
        $emailChanged = $user->email !== $data['email'];
        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'status' => $user->status,
            'email_verified' => $user->hasVerifiedEmail(),
        ];

        DB::transaction(function () use ($request, $user, $audit, $data, $roles, $emailChanged, $before): void {
            $user->fill(['name' => $data['name'], 'email' => $data['email'], 'status' => $data['status']]);
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->save();
            $user->syncRoles($roles);
            if ($data['status'] === 'suspended') {
                $this->revokeAccess($user);
            }
            $audit->record('user.account_updated', $request->user(), [], $user, $before, [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->sort()->values()->all(),
                'status' => $user->status,
                'email_verified' => $user->hasVerifiedEmail(),
            ]);
        });

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('success', $user->name.' account updated.');
    }

    public function sendPasswordResetLink(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }
        $audit->record('user.password_reset_link_sent', $request->user(), [], $user);

        return back()->with('success', 'A password reset link was sent to '.$user->email.'.');
    }

    public function destroy(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->assertCanDeleteAccount($request->user(), $user);
        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'status' => $user->status,
        ];

        DB::transaction(function () use ($request, $user, $audit, $before): void {
            $this->revokeAccess($user);
            $audit->record('user.account_deleted', $request->user(), [], $user, $before);
            $user->delete();
        });

        return back()->with('success', $user->name.' account deleted.');
    }

    public function restore(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        abort_unless($user->trashed(), 404);
        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'status' => $user->status,
            'deleted_at' => $user->deleted_at?->toISOString(),
        ];

        DB::transaction(function () use ($request, $user, $audit, $before): void {
            $user->restore();
            $audit->record('user.account_restored', $request->user(), [], $user, $before, [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->sort()->values()->all(),
                'status' => $user->status,
                'deleted_at' => null,
            ]);
        });

        return back()->with('success', $user->name.' account restored.');
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor && $actor->status === 'active' && $actor->hasRole(UserRole::SuperAdmin->value), 403);
    }

    /** @param list<string> $roles */
    private function assertCanChangeAccount(User $actor, User $user, array $roles, string $status): void
    {
        $keepsActiveSuperAdmin = $status === 'active' && in_array(UserRole::SuperAdmin->value, $roles, true);
        if ($actor->is($user) && ! $keepsActiveSuperAdmin) {
            throw ValidationException::withMessages(['roles' => 'You cannot remove or suspend your own super administrator account.']);
        }

        $removesLastActiveSuperAdmin = $user->status === 'active'
            && $user->hasRole(UserRole::SuperAdmin->value)
            && ! $keepsActiveSuperAdmin
            && User::role(UserRole::SuperAdmin->value)->where('status', 'active')->count() <= 1;
        if ($removesLastActiveSuperAdmin) {
            throw ValidationException::withMessages(['roles' => 'The last active super administrator cannot be demoted or suspended.']);
        }
    }

    private function assertCanDeleteAccount(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['account' => 'You cannot delete your own account.']);
        }

        if ($user->status === 'active'
            && $user->hasRole(UserRole::SuperAdmin->value)
            && User::role(UserRole::SuperAdmin->value)->where('status', 'active')->count() <= 1) {
            throw ValidationException::withMessages(['account' => 'The last active super administrator cannot be deleted.']);
        }
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}
