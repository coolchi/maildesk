<?php

namespace App\Jobs;

use App\Models\BroadcastRecipient;
use App\Services\BroadcastService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class SendBroadcastRecipient implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $recipientId) {}

    /**
     * Throttle provider calls (Resend's default limit is a few requests a
     * second). The sync queue can't release jobs, so it runs unthrottled.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        if (config('queue.default') === 'sync' || (int) config('maildesk.broadcasts.per_second', 2) <= 0) {
            return [];
        }

        return [new RateLimited('broadcast-sends')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(BroadcastService $broadcasts): void
    {
        $recipient = BroadcastRecipient::query()->with('broadcast.organization')->find($this->recipientId);

        if ($recipient === null || $recipient->status !== 'pending') {
            return;
        }

        try {
            $broadcasts->sendTo($recipient);
        } catch (Throwable $e) {
            $recipient->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
            report($e);
        }

        $broadcasts->finalizeIfDone($recipient->broadcast);
    }
}
