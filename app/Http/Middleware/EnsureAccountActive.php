<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-side suspension / closure enforcement. Runs after IdentifyTenant.
 *
 *  - Platform admins are never locked out (so /admin keeps working).
 *  - Regular users whose every organization is blocked are logged out.
 *  - Users with another usable organization are moved off a blocked one.
 */
class EnsureAccountActive
{
    public function __construct(public AccountAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        if ($this->access->isLockedOut($user)) {
            $message = $this->access->lockoutMessage($user);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $message], 403);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        $current = $request->attributes->get('organization');

        if ($current instanceof Organization && $this->access->organizationBlocked($current)) {
            if ($request->attributes->get('tenant_host_locked')) {
                abort(403, $this->access->reasonFor($current));
            }

            $fallback = $this->access->usableOrganizations($user)->first()?->loadMissing('mailProvider');

            $request->session()->put('current_organization_id', $fallback?->id);
            $request->attributes->set('organization', $fallback);
            if ($fallback) {
                app()->instance(Organization::class, $fallback);
            }
        }

        return $next($request);
    }
}
