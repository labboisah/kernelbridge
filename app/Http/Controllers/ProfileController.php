<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentAssets;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class ProfileController extends Controller
{
    public function photo(Request $request)
    {
        abort_unless($request->user()->can('admin.profile.manage'), 403);
        $path = $request->user()->admin_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function updatePhoto(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('admin.profile.manage'), 403);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $newPhoto = $request->file('photo')->store('admin-photos', 'local');
        if ($newPhoto === false) {
            throw new RuntimeException('Unable to store the administrator photo.');
        }
        try {
            $oldPhoto = DB::transaction(function () use ($request, $newPhoto): ?string {
                $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $oldPhoto = $user->admin_photo_path;
                $user->forceFill(['admin_photo_path' => $newPhoto])->save();

                return $oldPhoto;
            });
        } catch (Throwable $exception) {
            if (! Storage::disk('local')->delete($newPhoto)) {
                report(new RuntimeException('Unable to clean up an unused administrator photo.'));
            }
            throw $exception;
        }
        $request->user()->setAttribute('admin_photo_path', $newPhoto)->syncOriginalAttribute('admin_photo_path');
        if ($oldPhoto && ! Storage::disk('local')->delete($oldPhoto)) {
            report(new RuntimeException('Unable to remove a replaced administrator photo.'));
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, DocumentAssets $assets): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'documentAssets' => $request->user()->can('admin.profile.manage') ? $assets->images() : null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $photo = $user->admin_photo_path;

        Auth::logout();

        $user->forceDelete();
        if ($photo && ! Storage::disk('local')->delete($photo)) {
            report(new RuntimeException('Unable to remove a deleted administrator account photo.'));
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
