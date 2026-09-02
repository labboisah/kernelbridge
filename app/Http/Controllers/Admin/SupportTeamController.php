<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportTeamRequest;
use App\Models\SupportTeam;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class SupportTeamController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', SupportTeam::class);

        return Inertia::render('Admin/SupportTeams/Index', [
            'teams' => SupportTeam::query()->withCount('tickets')->with('members:id,name,email,public_id')->orderBy('sort_order')->orderBy('name')->get(),
            'staff' => User::permission('support.manage')->orderBy('name')->get(['id', 'public_id', 'name', 'email']),
        ]);
    }

    public function store(SupportTeamRequest $request): RedirectResponse
    {
        SupportTeam::query()->create($request->validated());

        return back()->with('success', 'Support team created.');
    }

    public function update(SupportTeamRequest $request, SupportTeam $supportTeam): RedirectResponse
    {
        $supportTeam->update($request->validated());

        return back()->with('success', 'Support team updated.');
    }

    public function destroy(SupportTeam $supportTeam): RedirectResponse
    {
        $this->authorize('delete', $supportTeam);
        abort_if($supportTeam->tickets()->exists(), 422, 'Teams with tickets cannot be deleted. Deactivate the team instead.');
        $supportTeam->delete();

        return back()->with('success', 'Support team deleted.');
    }

    public function addMember(SupportTeam $supportTeam): RedirectResponse
    {
        $this->authorize('update', $supportTeam);
        $data = request()->validate(['user_public_id' => ['required', 'string', 'exists:users,public_id']]);
        $user = User::query()->where('public_id', $data['user_public_id'])->firstOrFail();
        abort_unless($user->can('support.manage'), 422, 'Only support-authorized staff can join a support team.');
        $supportTeam->members()->syncWithoutDetaching([$user->id]);

        return back()->with('success', $user->name.' added to '.$supportTeam->name.'.');
    }

    public function removeMember(SupportTeam $supportTeam, string $userPublicId): RedirectResponse
    {
        $this->authorize('update', $supportTeam);
        $user = User::query()->where('public_id', $userPublicId)->firstOrFail();
        $supportTeam->members()->detach($user->id);

        return back()->with('success', $user->name.' removed from '.$supportTeam->name.'.');
    }
}
