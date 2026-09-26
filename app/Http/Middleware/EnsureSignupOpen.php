<?php

namespace App\Http\Middleware;

use App\Services\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSignupOpen
{
    public const MESSAGE = 'New sign-ups are currently closed.';

    public function __construct(protected PlatformSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->settings->signupOpen()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
    }
}
