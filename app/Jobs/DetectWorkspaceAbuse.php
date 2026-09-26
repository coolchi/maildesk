<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\AbuseDetectionService;
use App\Services\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DetectWorkspaceAbuse implements ShouldQueue
{
    use Queueable;

    public function handle(AbuseDetectionService $detector, PlatformSettings $settings): void
    {
        if (! $settings->aiFeatureEnabled('abuse_detection')) {
            return;
        }

        Organization::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(50, function ($organizations) use ($detector): void {
                foreach ($organizations as $organization) {
                    $result = $detector->scan($organization);

                    if (($result['severity'] ?? 'ok') !== 'ok') {
                        Log::warning('Abuse detection flagged workspace', [
                            'organization_id' => $organization->id,
                            'severity' => $result['severity'],
                            'flags' => collect($result['flags'] ?? [])->pluck('code')->all(),
                        ]);
                    }
                }
            });
    }
}
