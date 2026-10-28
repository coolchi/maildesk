<?php

namespace App\Http\Middleware;

use App\Http\Responses\AppErrorPage;
use App\Models\Organization;
use App\Services\AccountAccess;
use App\Services\WorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After trial expiry (past_due), only Settings, Billing, Profile, and logout
 * remain reachable until Monipay payment restores active status.
 */
class EnsureWorkspaceEntitled
{
    public function __construct(
        public AccountAccess $access,
        private WorkspaceAccess $workspace,
        private AppErrorPage $errors,
    ) {}

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

        // Members cannot open Settings. Sending them there only produces a raw 403.
        if (! $this->workspace->can($user, $organization, 'manage')) {
            return $this->errors->render(
                $request,
                403,
                AccountAccess::TRIAL_EXPIRED_MEMBER_MESSAGE,
                'Workspace paused',
            );
        }

        return redirect()
            ->route('settings', 'billing')
            ->with('error', $this->access->paymentRequiredMessage($organization));
    }
}
