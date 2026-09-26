<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TenantResolver;
use App\Support\UserRegistrationSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request, TenantResolver $tenants): Response|RedirectResponse
    {
        if ($redirect = $this->workspaceHostRedirect($request, $tenants)) {
            return $redirect;
        }

        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, TenantResolver $tenants): RedirectResponse
    {
        if ($redirect = $this->workspaceHostRedirect($request, $tenants)) {
            return $redirect;
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    protected function workspaceHostRedirect(Request $request, TenantResolver $tenants): ?RedirectResponse
    {
        $organization = $tenants->resolveFromHost($tenants->hostFromRequest($request));

        if (! $organization) {
            return null;
        }

        if (UserRegistrationSettings::enabled($organization)) {
            return redirect()->route('tenant.join');
        }

        return redirect()->route('login');
    }
}
