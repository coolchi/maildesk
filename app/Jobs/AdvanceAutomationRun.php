<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Services\AutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdvanceAutomationRun implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $automationRunId) {}

    public function handle(AutomationService $automations): void
    {
        $run = AutomationRun::query()->find($this->automationRunId);

        if ($run === null) {
            return;
        }

        $automations->advance($run);
    }
}
