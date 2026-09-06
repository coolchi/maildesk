<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
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

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return response()->json(['message' => 'API key expired.'], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('organization', $apiKey->organization);

        return $next($request);
    }
}
