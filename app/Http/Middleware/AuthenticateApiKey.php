<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\EmailController;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates `Authorization: Bearer md_...` keys, applies the per-key
 * rate limit (maildesk.api.rate_limit requests per minute) and enforces the
 * key's abilities: "Sending access" keys (emails:send) may only call
 * POST /emails; every other endpoint needs a full-access (*) key.
 * The domain:<name> scope is enforced by the send endpoint itself.
 */
class AuthenticateApiKey
{
    /** Controller actions a sending-only key may call, and the ability each needs. */
    public const ACTION_ABILITIES = [
        EmailController::class.'@store' => 'emails:send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_starts_with($token, 'md_')) {
            return response()->json([
                'message' => 'Missing or invalid API key. Use Authorization: Bearer md_...',
            ], 401);
        }

        $prefix = substr($token, 0, 12);

        /** @var ApiKey|null $apiKey */
        $apiKey = ApiKey::query()
            ->where('key_prefix', $prefix)
            ->with('organization')
            ->first();

        if (! $apiKey || ! $apiKey->matches($token)) {
            return response()->json(['message' => 'Invalid API key.'], 401);
        }

        if ($apiKey->isRevoked()) {
            return response()->json(['message' => 'API key revoked.'], 401);
        }

        if ($apiKey->isExpired()) {
            return response()->json(['message' => 'API key expired.'], 401);
        }

        $limit = max(1, (int) config('maildesk.api.rate_limit', 120));
        $limiterKey = 'api-key:'.$apiKey->id;

        if (RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            return response()->json(['message' => 'Too many requests. Slow down and retry later.'], 429, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
            ]);
        }

        RateLimiter::hit($limiterKey, 60);
        $remaining = max(0, $limit - RateLimiter::attempts($limiterKey));

        $required = self::ACTION_ABILITIES[$request->route()?->getActionName() ?? ''] ?? '*';

        if (! $apiKey->can($required)) {
            return $this->withLimitHeaders(response()->json([
                'message' => 'This API key does not have permission to access this endpoint. Sending-only keys can only call POST /api/v1/emails.',
            ], 403), $limit, $remaining);
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('organization', $apiKey->organization);

        return $this->withLimitHeaders($next($request), $limit, $remaining);
    }

    private function withLimitHeaders(Response $response, int $limit, int $remaining): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
