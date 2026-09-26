<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\AccountAccess;
use App\Services\BroadcastService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledBroadcast implements ShouldQueue
{
    use Queueable;

    public function handle(BroadcastService $broadcasts, AccountAccess $access): void
    {
        Broadcast::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get()
            ->each(function (Broadcast $broadcast) use ($broadcasts, $access): void {
                $organization = $broadcast->organization()->first();

                if (! $organization || $access->organizationBlocked($organization)) {
                    $broadcast->forceFill([
                        'status' => 'failed',
                        'scheduled_at' => null,
                    ])->save();

                    return;
                }

                $broadcasts->queue($broadcast);
            });
    }
}
