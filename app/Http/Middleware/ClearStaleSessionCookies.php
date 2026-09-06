<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ClearStaleSessionCookies
{
    /**
     * Expire legacy session cookie names only. Avoid rewriting the current
     * session / XSRF cookies on every response (that caused host/domain races).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $current = (string) config('session.cookie');
        $domain = config('session.domain');
        $path = (string) config('session.path', '/');
        $secure = (bool) config('session.secure');
        $sameSite = config('session.same_site');

        foreach (['maildesk_session', 'maildesk_session_v2', 'maildesk_session_v3', 'laravel_session'] as $name) {
            if ($name === $current || ! $request->cookies->has($name)) {
                continue;
            }

            $this->expire($response, $name, null, $path, $secure, $sameSite);

            if (filled($domain)) {
                $this->expire($response, $name, $domain, $path, $secure, $sameSite);
            }
        }

        return $response;
    }

    private function expire(
        Response $response,
        string $name,
        ?string $domain,
        string $path,
        bool $secure,
        ?string $sameSite,
    ): void {
        $response->headers->setCookie(new Cookie(
            name: $name,
            value: '',
            expire: 1,
            path: $path,
            domain: $domain,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: $sameSite,
        ));
    }
}
