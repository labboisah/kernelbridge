<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', 'string'], 'status' => ['nullable', 'string']]);
        $staff = User::query()->role(UserRole::administrative())->with('roles:id,name')
            ->when($filters['search'] ?? null, fn (Builder $query, string $value) => $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$value}%")->orWhere('email', 'like', "%{$value}%")))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderBy('name')->paginate(20)->withQueryString();

        return Inertia::render('Admin/Staff/Index', ['staff' => $staff, 'filters' => $filters, 'roles' => UserRole::administrative()]);
    }

    public function store(StaffRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $staff = DB::transaction(function () use ($request, $audit): User {
            $data = $request->safe()->except(['role']);
            $data['email_verified_at'] = now();
            $staff = User::query()->create($data);
            $staff->syncRoles([$request->validated('role')]);
            $audit->record('staff.created', $request->user(), ['role' => $request->validated('role')], $staff, null, $staff->only(['public_id', 'name', 'email', 'status']));

            return $staff;
        });

        return back()->with('success', $staff->name.' was added to the staff directory.');
    }

    public function update(StaffRequest $request, User $staff, AuditRecorder $audit): RedirectResponse
    {
        if ($request->user()->is($staff) && $request->validated('status') !== 'active') {
            throw ValidationException::withMessages(['status' => 'You cannot suspend your own account.']);
        }
        if ($staff->hasRole(UserRole::SuperAdmin->value) && ($request->validated('role') !== UserRole::SuperAdmin->value || $request->validated('status') !== 'active') && User::role(UserRole::SuperAdmin->value)->where('status', 'active')->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'The last active super administrator cannot be demoted or suspended.']);
        }

        DB::transaction(function () use ($request, $staff, $audit): void {
            $before = [...$staff->only(['name', 'email', 'status']), 'role' => $staff->getRoleNames()->first()];
            $data = $request->safe()->except(['role', 'password']);
            if ($request->filled('password')) {
                $data['password'] = $request->validated('password');
            }
            $staff->update($data);
            $staff->syncRoles([$request->validated('role')]);
            $after = [...$staff->only(['name', 'email', 'status']), 'role' => $request->validated('role')];
            $audit->record('staff.updated', $request->user(), [], $staff, $before, $after);
        });

        return back()->with('success', 'Staff account updated.');
    }
}
