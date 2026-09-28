<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like Laravel's `password.confirm`, but with a short, configurable window
 * and safe for POST routes: instead of remembering the POST URL as the
 * "intended" destination (which would be replayed as a GET), it sends the
 * user back to the page they came from after confirming.
 */
class RequireFreshPassword
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $seconds = null): Response
    {
        $window = (int) ($seconds ?: config('impersonation.password_timeout_seconds', 600));
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (Date::now()->unix() - $confirmedAt <= $window) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Password confirmation required.'], 423);
        }

        $back = url()->previous();
        $sameHost = parse_url($back, PHP_URL_HOST) === $request->getHost();
        $returnTo = $sameHost ? $back : url('/');

        if ($request->routeIs('admin.impersonate', 'team.impersonate')) {
            $target = $request->route('user');
            $organizationId = $request->routeIs('team.impersonate')
                ? ($request->attributes->get('organization')?->id
                    ?? $request->session()->get('current_organization_id'))
                : $request->input('organization_id');

            $request->session()->put('impersonation.pending', [
                'actor_id' => $request->user()?->id,
                'user_id' => $target instanceof User ? $target->id : (int) $target,
                'organization_id' => $organizationId ? (int) $organizationId : null,
                'reason' => $request->input('reason'),
                'return' => $returnTo,
            ]);
            $request->session()->put('url.intended', route('impersonate.resume'));

            return redirect()->route('password.confirm');
        }

        $request->session()->put('url.intended', $returnTo);

        return redirect()->route('password.confirm');
    }
}
