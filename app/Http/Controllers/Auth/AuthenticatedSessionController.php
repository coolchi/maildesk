<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Impersonation\ImpersonationService;
use App\Services\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $organization = app(TenantResolver::class)->resolveFromHost($request->getHost());
        if ($organization) {
            $request->session()->put('current_organization_id', $organization->id);

            return redirect()->intended(route('dashboard', absolute: false));
        }

        $home = $request->user()?->isPlatformAdmin()
            ? route('admin.dashboard', absolute: false)
            : route('dashboard', absolute: false);

        return redirect()->intended($home);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, ImpersonationService $impersonation): RedirectResponse|SymfonyResponse
    {
        // While impersonating, "log out" ends the impersonation and returns
        // the admin to the admin panel. Auth::logout() must NOT run here: it
        // would cycle the impersonated user's remember_token and sign them
        // out of their other remembered devices.
        if ($impersonation->isImpersonating($request)) {
            $url = $impersonation->stop($request, 'logout');

            return $request->header('X-Inertia')
                ? Inertia::location($url)
                : redirect()->away($url);
        }

        // Sign out this device only. logoutCurrentDevice() removes the user
        // from the session and expires this browser's remember cookie, but
        // (unlike logout()) does not cycle users.remember_token, so the
        // user's other remembered devices stay signed in.
        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
