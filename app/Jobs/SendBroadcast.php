<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\BroadcastService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolves a broadcast's audience into recipient rows, then fans out one
 * queued send per recipient.
 */
class SendBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $broadcastId) {}

    public function handle(BroadcastService $broadcasts): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if ($broadcast === null || ! in_array($broadcast->status, ['queued', 'sending'], true)) {
            return;
        }

        $broadcasts->buildRecipients($broadcast);
        $broadcast->forceFill(['status' => 'sending'])->save();

        $pending = $broadcast->recipients()->where('status', 'pending')->pluck('id');

        foreach ($pending as $recipientId) {
            SendBroadcastRecipient::dispatch($recipientId);
        }

        // Nothing to send (empty audience, or everyone was skipped).
        $broadcasts->finalizeIfDone($broadcast);
    }
}
