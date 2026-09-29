<?php

use App\Http\Middleware\ClearStaleSessionCookies;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserHasWorkspace;
use App\Http\Middleware\EnsureWorkspaceAbility;
use App\Http\Middleware\EnsureWorkspaceEntitled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ImpersonationGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
        then: function () {
            Broadcast::routes([
                'middleware' => ['auth:sanctum'],
                'prefix' => 'api/app',
            ]);

            Route::middleware('api')
                ->prefix('api/app')
                ->group(base_path('routes/mobile.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            ClearStaleSessionCookies::class,
        ]);

        $middleware->web(append: [
            // Read-only + TTL enforcement and audit for admin "log in as".
            ImpersonationGuard::class,
            IdentifyTenant::class,
            EnsureUserHasWorkspace::class,
            // Suspended / closed accounts: log out, block, refuse impersonation.
            EnsureAccountActive::class,
            // Expired trial / past_due: Settings + Billing only until payment.
            EnsureWorkspaceEntitled::class,
            EnsureWorkspaceAbility::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // One-click unsubscribe (RFC 8058) is POSTed by mail providers
        // without a CSRF token; the signed URL protects it instead.
        $middleware->validateCsrfTokens(except: ['unsubscribe/*']);

        $middleware->alias([
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
