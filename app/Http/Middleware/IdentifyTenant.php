<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
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

        if ($fromHost && ! $user->isPlatformAdmin() && ! $user->organizations()->whereKey($fromHost->id)->exists()) {
            abort(403, 'You do not have access to this workspace.');
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
}
