<?php

namespace App\Http\Responses;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders HTTP errors inside the MailDesk shell instead of Laravel's plain page.
 */
class AppErrorPage
{
    /** @var list<int> */
    private const ALWAYS = [403, 404, 405, 419, 429];

    /** @var list<int> */
    private const WHEN_NOT_DEBUG = [500, 503];

    private static bool $sharing = false;

    private static bool $rendering = false;

    public function __construct(private HandleInertiaRequests $inertia) {}

    public function replace(Response $response, ?Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($status === 419) {
            return $this->expired($request);
        }

        if (self::$rendering || ! $this->shouldReplace($response, $request, $status)) {
            return $response;
        }

        return $this->render(
            $request,
            $status,
            $this->messageFor($status, $exception, $response),
        );
    }

    public function render(Request $request, int $status, string $message, ?string $title = null): Response
    {
        if (self::$rendering) {
            return $this->blade($request, $status, $title ?? $this->titleFor($status), $message);
        }

        self::$rendering = true;

        try {
            $title ??= $this->titleFor($status);

            if ($this->wantsInertia($request)) {
                $this->ensureShared($request);

                return Inertia::render('Error', [
                    'status' => $status,
                    'title' => $title,
                    'message' => $message,
                ])->toResponse($request)->setStatusCode($status);
            }

            return $this->blade($request, $status, $title, $message);
        } catch (Throwable $e) {
            Log::warning('Could not render in-app error page', ['error' => $e->getMessage()]);

            return $this->blade($request, $status, $title ?? $this->titleFor($status), $message);
        } finally {
            self::$rendering = false;
        }
    }

    public function titleFor(int $status): string
    {
        return match ($status) {
            403 => 'You do not have access',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Page expired',
            429 => 'Too many requests',
            503 => 'Temporarily unavailable',
            default => 'Something went wrong',
        };
    }

    private function expired(Request $request): Response
    {
        $message = $this->generic(419);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 419);
        }

        $fallback = $request->user()
            ? route('dashboard')
            : route('login');

        return redirect()
            ->back(fallback: $fallback)
            ->with('error', $message);
    }

    private function shouldReplace(Response $response, Request $request, int $status): bool
    {
        $tracked = in_array($status, self::ALWAYS, true)
            || (in_array($status, self::WHEN_NOT_DEBUG, true) && ! config('app.debug'));

        if (! $tracked || $response->isRedirection()) {
            return false;
        }

        if ($request->is('api/*') || $response->headers->has('X-Inertia')) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type');

        if (str_contains($type, 'application/json')) {
            return false;
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return false;
        }

        return true;
    }

    private function wantsInertia(Request $request): bool
    {
        if ($request->header('X-Inertia')) {
            return true;
        }

        return $request->accepts(['text/html']) && ! $request->expectsJson();
    }

    private function blade(Request $request, int $status, string $title, string $message): Response
    {
        $signedIn = (bool) $request->user();

        return response()->view('errors.show', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'homeUrl' => $signedIn ? url('/dashboard') : url('/login'),
            'homeLabel' => $signedIn ? 'Go to dashboard' : 'Log in',
            'signedIn' => $signedIn,
            'showLogout' => $signedIn && $status === 403,
            'profileUrl' => $signedIn ? route('profile.edit') : null,
            'logoutUrl' => route('logout'),
        ], $status);
    }

    private function messageFor(int $status, ?Throwable $exception, Response $response): string
    {
        if (in_array($status, [405, 429, 500, 503], true)) {
            return $this->generic($status);
        }

        $raw = trim((string) ($exception?->getMessage() ?? ''));

        if ($raw === '') {
            $body = $response->getContent();
            if (is_string($body) && $body !== '' && strlen($body) < 300 && ! str_contains($body, '<')) {
                $raw = trim($body);
            }
        }

        $generic = [
            '',
            'Forbidden',
            'Not Found',
            'Method Not Allowed',
            'Page Expired',
            'Too Many Requests',
            'Server Error',
            'Service Unavailable',
            'This action is unauthorized.',
        ];

        if (
            in_array($raw, $generic, true)
            || str_contains($raw, 'No query results')
            || str_contains($raw, '\\')
            || str_starts_with($raw, 'The route ')
        ) {
            return $this->generic($status);
        }

        return $raw;
    }

    private function generic(int $status): string
    {
        return match ($status) {
            403 => 'You do not have access to this area.',
            404 => 'That page could not be found.',
            405 => 'That action is not available for this page. Go back and try again.',
            419 => 'This page expired. Refresh and try again.',
            429 => 'You have made too many requests. Wait a moment and try again.',
            503 => 'MailDesk is temporarily unavailable. Try again shortly.',
            default => 'Something went wrong. Try again in a moment.',
        };
    }

    private function ensureShared(Request $request): void
    {
        if (Inertia::getShared('csrf_token') !== null || self::$sharing) {
            return;
        }

        self::$sharing = true;

        try {
            if (! $request->hasSession()) {
                $session = app('session')->driver();
                $session->start();
                $request->setLaravelSession($session);
            }

            Inertia::setRootView('app');
            Inertia::share($this->inertia->share($request));
        } catch (Throwable $e) {
            Log::warning('Could not share app data for error page', ['error' => $e->getMessage()]);
        } finally {
            self::$sharing = false;
        }
    }
}
