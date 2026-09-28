<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in user with no workspace cannot open mail routes.
 * Send them to create one instead of a 404.
 */
class EnsureUserHasWorkspace
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isPlatformAdmin()) {
            return $next($request);
        }

        if ($request->attributes->get('organization') instanceof Organization) {
            return $next($request);
        }

        if ($this->allowedWithoutWorkspace($request)) {
            return $next($request);
        }

        return redirect()->route('workspaces.create');
    }

    protected function allowedWithoutWorkspace(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (! is_string($name) || $name === '') {
            return false;
        }

        if (in_array($name, ['logout', 'workspaces.create', 'workspaces.store', 'docs.send'], true)) {
            return true;
        }

        return str_starts_with($name, 'profile.')
            || str_starts_with($name, 'verification.')
            || str_starts_with($name, 'password.');
    }
}
