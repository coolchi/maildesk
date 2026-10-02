<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile API equivalent of EnsureWorkspaceEntitled for web routes.
 *
 * After trial expiry (past_due), only read operations are allowed.
 * Write operations (send, reply, create contacts) are blocked with 402.
 */
class EnsureMobileWorkspaceEntitled
{
    public function __construct(public AccountAccess $access) {}

    /**
     * Routes that are blocked when payment is required.
     * Read operations (GET) are generally allowed.
     */
    private const BLOCKED_ROUTES = [
        'mobile.emails.store',
        'mobile.inbox.reply',
        'mobile.contacts.store',
        'mobile.inbox.archive',
        'mobile.inbox.spam',
        'mobile.inbox.trash',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->resolveOrganization($request);

        if (! $organization) {
            return $next($request);
        }

        if (! $this->access->requiresPayment($organization)) {
            return $next($request);
        }

        if ($this->isBlockedRoute($request)) {
            return response()->json([
                'message' => $this->access->paymentRequiredMessage($organization),
                'payment_required' => true,
            ], 402);
        }

        return $next($request);
    }

    protected function resolveOrganization(Request $request): ?Organization
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');

        if (! $workspaceId) {
            return null;
        }

        $user = $request->user();
        if (! $user) {
            return null;
        }

        return $user->organizations()->whereKey($workspaceId)->first();
    }

    protected function isBlockedRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if ($routeName && in_array($routeName, self::BLOCKED_ROUTES, true)) {
            return true;
        }

        return false;
    }
}
