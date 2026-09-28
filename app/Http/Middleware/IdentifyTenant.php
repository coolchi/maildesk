<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function __construct(public TenantResolver $tenants) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $host = $this->tenants->hostFromRequest($request);
        $fromHost = $this->tenants->resolveFromHost($host);
        $hostLocked = $fromHost !== null;

        if ($fromHost && ! $user->organizations()->whereKey($fromHost->id)->exists()) {
            // Public auth / join entry points must stay reachable so visitors can
            // switch accounts or self-register on this workspace host.
            if ($this->isPublicTenantEntryRoute($request)) {
                $request->attributes->set('organization', null);
                $request->attributes->set('tenant_host_locked', false);

                return $next($request);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->guest(route('login'))
                ->with('status', 'Sign in with an account that belongs to this workspace.');
        }

        $organization = $this->tenants->resolveForUser($request, $user);

        if ($organization) {
            $request->session()->put('current_organization_id', $organization->id);
            $request->attributes->set('organization', $organization);
            $request->attributes->set('tenant_host_locked', $hostLocked);
            app()->instance(Organization::class, $organization);
        } else {
            $request->attributes->set('organization', null);
            $request->attributes->set('tenant_host_locked', false);
        }

        return $next($request);
    }

    /**
     * Routes that unaffiliated users may hit on a tenant host without a hard 403.
     */
    protected function isPublicTenantEntryRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (! is_string($name) || $name === '') {
            return false;
        }

        if (in_array($name, [
            'login',
            'logout',
            'tenant.join',
            'tenant.join.store',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'docs.send',
        ], true)) {
            return true;
        }

        return str_starts_with($name, 'password.');
    }
}
