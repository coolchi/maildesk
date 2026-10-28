<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\AccountAccess;
use App\Services\Chat\PresenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileWorkspace
{
    public function __construct(public AccountAccess $access, public PresenceService $presence) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = (int) $request->header('X-Organization-Id');

        if ($organizationId === 0) {
            return response()->json([
                'message' => 'Choose a workspace.',
            ], 422);
        }

        /** @var Organization|null $organization */
        $organization = $user?->organizations()->whereKey($organizationId)->first();

        if ($organization === null) {
            return response()->json([
                'message' => 'Workspace not found.',
            ], 404);
        }

        if ($this->access->organizationBlocked($organization)) {
            return response()->json([
                'message' => $this->access->reasonFor($organization),
            ], 403);
        }

        if ($this->access->requiresPayment($organization)) {
            return response()->json([
                'message' => $this->access->paymentRequiredMessage($organization),
            ], 402);
        }

        $request->attributes->set('organization', $organization);
        if ($user !== null) {
            $this->presence->touch($user);
        }

        return $next($request);
    }
}
