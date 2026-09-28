<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Organization;
use App\Services\WorkspaceAccess;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, WorkspaceAccess $access): Response
    {
        $mailbox = null;
        $organization = $request->attributes->get('organization');

        if ($organization instanceof Organization) {
            $linked = $access->mailboxFor($request->user(), $organization);

            if ($linked) {
                $mailbox = [
                    'id' => $linked->id,
                    'email' => $linked->email,
                    'display_name' => $linked->display_name,
                    'signature' => (string) ($linked->signature ?? ''),
                ];
            }
        }

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'mailbox' => $mailbox,
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
     * Update notification / UI preferences.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'inbox_sound' => ['required', 'boolean'],
        ]);

        $request->user()->mergePreferences([
            'inbox_sound' => $validated['inbox_sound'],
        ]);

        return Redirect::route('profile.edit')->with('success', 'Preferences saved.');
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

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
