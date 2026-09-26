<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enforces product / manage abilities for workspace routes.
 */
class EnsureWorkspaceAbility
{
    public function __construct(public WorkspaceAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $ability = $this->access->abilityForRoute($request->route()?->getName());

        if ($ability === null) {
            return $next($request);
        }

        try {
            $organization = CurrentOrganization::from($request);
        } catch (NotFoundHttpException) {
            return $next($request);
        }

        if ($this->access->can($user, $organization, $ability)) {
            return $next($request);
        }

        abort(403, 'You do not have access to this area.');
    }
}
