<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\AutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAutomationEvent implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $organizationId,
        public string $eventName,
        public array $payload = [],
    ) {}

    public function handle(AutomationService $automations): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $runs = $automations->startRunsForEvent($organization, $this->eventName, $this->payload);

        foreach ($runs as $run) {
            AdvanceAutomationRun::dispatch($run->id);
        }
    }
}
