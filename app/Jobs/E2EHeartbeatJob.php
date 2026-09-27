<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * A simple job that sets a cache key to verify the queue worker is running.
 * Used by the E2E mail test to detect if jobs are being processed.
 */
class E2EHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $cacheKey,
    ) {}

    public function handle(): void
    {
        Cache::put($this->cacheKey, 'alive', now()->addMinutes(5));
    }
}
