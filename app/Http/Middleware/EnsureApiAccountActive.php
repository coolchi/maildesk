<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after AuthenticateApiKey: API keys of suspended or closed accounts
 * get a 403 in the standard {"message": "..."} API error format.
 */
class EnsureApiAccountActive
{
    public function __construct(public AccountAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->attributes->get('organization');

        if (! $organization instanceof Organization || $this->access->organizationBlocked($organization)) {
            return response()->json([
                'message' => $this->access->reasonFor($organization instanceof Organization ? $organization : null),
            ], 403);
        }

        if ($this->access->requiresPayment($organization)) {
            return response()->json([
                'message' => $this->access->paymentRequiredMessage($organization),
            ], 402);
        }

        return $next($request);
    }
}
