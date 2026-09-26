<?php

namespace App\Http\Middleware;

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
        $request->session()->put('url.intended', $sameHost ? $back : url('/'));

        return redirect()->route('password.confirm');
    }
}
