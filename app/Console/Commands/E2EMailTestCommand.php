<?php

namespace App\Console\Commands;

use App\Models\E2ETestRun;
use App\Services\E2EMailTestService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class E2EMailTestCommand extends Command
{
    protected $signature = 'maildesk:e2e
        {--events : Also test delivery events with Resend test addresses}
        {--timeout=180 : Maximum seconds to wait for inbound email}
        {--from= : Override the sender address}
        {--mailbox= : Override the test mailbox address}';

    protected $description = 'Run end-to-end mail test to verify sending and receiving work';

    public function handle(E2EMailTestService $service): int
    {
        $this->info('Starting E2E mail test...');
        $this->newLine();

        if ($this->option('from')) {
            config(['maildesk.e2e.from' => $this->option('from')]);
        }

        if ($this->option('mailbox')) {
            config(['maildesk.e2e.mailbox' => $this->option('mailbox')]);
        }

        if ($this->option('timeout')) {
            config(['maildesk.e2e.timeout' => (int) $this->option('timeout')]);
        }

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'cli',
            'include_events' => $this->option('events'),
        ]);

        $this->info("Test run ID: {$run->id}");
        $this->info("Token: {$run->token}");
        $this->newLine();

        $result = $service->execute($run);
        $run = $result['run'];

        $this->outputResults($run);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    protected function outputResults(E2ETestRun $run): void
    {
        $this->newLine();

        if ($run->status === 'passed') {
            $this->info('✓ E2E test PASSED');
        } else {
            $this->error('✗ E2E test FAILED');
        }

        $this->newLine();
        $this->info('Step Results:');

        $steps = $run->steps ?? [];
        $timings = $run->timings ?? [];

        foreach ($steps as $step => $data) {
            $icon = ($data['success'] ?? false) ? '✓' : '✗';
            $time = isset($timings[$step]) ? number_format($timings[$step], 0).'ms' : '-';
            $detail = $data['detail'] ?? '';

            $line = "  {$icon} {$step}: {$time}";
            if ($detail) {
                $line .= " ({$detail})";
            }

            if ($data['success'] ?? false) {
                $this->line($line);
            } else {
                $this->error($line);
            }
        }

        if ($run->failed_step) {
            $this->newLine();
            $this->error("Failed at: {$run->failed_step}");
            $this->error("Error: {$run->error}");

            if ($run->error_hint) {
                $this->newLine();
                $this->warn("Hint: {$run->error_hint}");
            }
        }

        $this->newLine();

        if ($run->started_at && $run->completed_at) {
            $duration = $run->completed_at->diffInMilliseconds($run->started_at);
            $this->info("Total duration: {$duration}ms");
        }
    }
}
