<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After trial expiry (past_due), only Settings, Billing, Profile, and logout
 * remain reachable until Monipay payment restores active status.
 */
class EnsureWorkspaceEntitled
{
    public function __construct(public AccountAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('organization');

        if (! $user || ! $organization || $user->isPlatformAdmin()) {
            return $next($request);
        }

        if (! $this->access->requiresPayment($organization)) {
            return $next($request);
        }

        if ($this->access->routeAllowedWhileLocked($request)) {
            return $next($request);
        }

        if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return response()->json([
                'message' => $this->access->paymentRequiredMessage($organization),
            ], 402);
        }

        return redirect()
            ->route('settings', 'billing')
            ->with('error', $this->access->paymentRequiredMessage($organization));
    }
}
