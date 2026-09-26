<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationLog;
use App\Models\User;
use App\Services\Impersonation\ImpersonationDenied;
use App\Services\Impersonation\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces read-only, time-boxed impersonation and audits every request
 * made while an authorized actor is logged in as another user.
 *
 * Registered in the web group just before IdentifyTenant so expiry/revocation
 * is handled before tenant resolution and even tenant 403s are audited.
 */
class ImpersonationGuard
{
    public const READ_ONLY_MESSAGE = 'Read-only while impersonating.';

    public function __construct(public ImpersonationService $impersonation) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonation->isImpersonating($request)) {
            return $next($request);
        }

        $log = $this->impersonation->currentLog($request);

        if ($endReason = $this->endReason($request, $log)) {
            return $this->end($request, $log, $endReason);
        }

        if ($this->shouldBlock($request)) {
            return $this->block($request, $log);
        }

        $response = $next($request);

        $this->impersonation->recordAction($log->id, $request, $response, false);

        return $response;
    }

    protected function endReason(Request $request, ?ImpersonationLog $log): ?string
    {
        $actor = $log?->admin;
        $user = $request->user();

        if ($log === null || $user === null || (int) $log->user_id !== (int) $user->id) {
            return 'revoked';
        }

        if ($actor === null || (int) $actor->id !== (int) $this->impersonation->impersonatorId($request)) {
            return 'revoked';
        }

        if (! $this->actorStillAuthorized($actor, $user, $log)) {
            return 'revoked';
        }

        if (! $log->isOpen() || $this->impersonation->hasExpired($request)) {
            return 'expired';
        }

        return null;
    }

    protected function actorStillAuthorized(User $actor, User $target, ImpersonationLog $log): bool
    {
        $organization = $log->organization;

        if ($organization === null) {
            return false;
        }

        try {
            $this->impersonation->assertActorMayImpersonate($actor, $target, $organization);
        } catch (ImpersonationDenied) {
            return false;
        }

        return true;
    }

    protected function end(Request $request, ?ImpersonationLog $log, string $reason): Response
    {
        $url = $this->impersonation->stop($request, $reason);

        $request->session()->flash('error', $reason === 'expired'
            ? 'Impersonation ended: the '.$this->impersonation->ttlMinutes().'-minute session expired.'
            : 'Impersonation ended: access was revoked.');

        $response = $request->header('X-Inertia')
            ? Inertia::location($url)
            : redirect()->away($url);

        if ($log) {
            $this->impersonation->recordAction($log->id, $request, $response, true);
        }

        return $response;
    }

    protected function shouldBlock(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        // Explicit belt-and-braces on top of EnsurePlatformAdmin.
        if ($request->is('admin', 'admin/*')) {
            return true;
        }

        if ($routeName !== null && Str::is(config('impersonation.denied_routes', []), $routeName)) {
            return true;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        return ! in_array($routeName, config('impersonation.allowed_mutations', []), true);
    }

    protected function block(Request $request, ImpersonationLog $log): Response
    {
        // Inertia form submissions get bounced back with a flash toast so
        // the page stays usable; everything else gets a plain 403.
        if ($request->header('X-Inertia') && ! $request->isMethodSafe()) {
            $response = redirect()->back(303)->with('error', self::READ_ONLY_MESSAGE);
        } elseif ($request->expectsJson()) {
            $response = response()->json(['message' => self::READ_ONLY_MESSAGE], 403);
        } else {
            $response = response(self::READ_ONLY_MESSAGE, 403);
        }

        $this->impersonation->recordAction($log->id, $request, $response, true);

        return $response;
    }
}
