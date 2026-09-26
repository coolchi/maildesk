<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\Impersonation\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-side suspension / closure enforcement. Runs after IdentifyTenant.
 *
 *  - Platform admins are never locked out (so /admin keeps working).
 *  - Admins cannot start impersonating a suspended or closed account, and an
 *    open impersonation session ends as soon as the account is blocked
 *    (conservative choice: refuse rather than allow read-only access).
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

        $impersonation = app(ImpersonationService::class);

        if ($impersonation->isImpersonating($request)) {
            $organization = $request->attributes->get('organization');

            if ($organization instanceof Organization && $this->access->organizationBlocked($organization)) {
                $message = 'Impersonation ended: '.$this->access->reasonFor($organization);
                $url = $impersonation->stop($request, 'account_blocked');
                $request->session()->flash('error', $message);

                return $request->header('X-Inertia')
                    ? Inertia::location($url)
                    : redirect()->to($url);
            }

            return $next($request);
        }

        if ($user->isPlatformAdmin()) {
            if ($request->routeIs('admin.impersonate')) {
                $target = Organization::withTrashed()->find($request->integer('organization_id'));

                if ($target && $this->access->organizationBlocked($target)) {
                    $message = 'Cannot log in as a user of this account: '.$this->access->reasonFor($target);

                    $targetUser = $request->route('user');
                    $impersonation->recordDenied(
                        $request,
                        $user,
                        $targetUser instanceof User ? $targetUser : null,
                        $target,
                        $message,
                    );

                    return back()->withErrors(['user' => $message])->with('error', $message);
                }
            }

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
