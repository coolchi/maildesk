<?php

namespace App\Jobs;

use App\Models\E2ETestRun;
use App\Services\E2EMailTestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the E2E mail test asynchronously from the admin page.
 */
class RunE2EMailTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $runId,
    ) {}

    public function handle(E2EMailTestService $service): void
    {
        $run = E2ETestRun::query()->find($this->runId);

        if (! $run) {
            Log::warning('E2E test run not found', ['run_id' => $this->runId]);

            return;
        }

        if ($run->status !== 'pending') {
            Log::info('E2E test run already started', ['run_id' => $this->runId, 'status' => $run->status]);

            return;
        }

        $service->execute($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = E2ETestRun::query()->find($this->runId);

        if ($run && $run->status === 'running') {
            $run->markFailed('job', $exception?->getMessage() ?? 'Job failed unexpectedly', 'The job was terminated. Check queue worker logs.');
        }

        Log::error('E2E mail test job failed', [
            'run_id' => $this->runId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
