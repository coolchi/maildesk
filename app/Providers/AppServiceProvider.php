<?php

namespace App\Providers;

use App\Mail\MailManager;
use App\Services\Imap\ImapConnection;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MailManager::class);

        $this->app->bind(ImapConnection::class, function () {
            $cfg = config('maildesk.stalwart.clients.imap', []);

            return new ImapConnection(
                host: (string) ($cfg['host'] ?? '127.0.0.1'),
                port: (int) ($cfg['port'] ?? 993),
                encryption: strtolower((string) ($cfg['encryption'] ?? 'ssl')),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('broadcast-sends', fn () => Limit::perSecond(max(1, (int) config('maildesk.broadcasts.per_second', 2))));
    }
}
