<?php

namespace App\Jobs;

use App\Models\GroupAddress;
use App\Models\Message;
use App\Services\EmailService;
use App\Services\GroupAddressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Deliver one member's copy of a message that arrived at a group address.
 */
class FanOutGroupMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(
        public int $messageId,
        public int $groupId,
        public string $memberEmail,
    ) {}

    /**
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

    public function handle(GroupAddressService $groups, EmailService $emails): void
    {
        $original = Message::query()->find($this->messageId);
        $group = GroupAddress::query()->find($this->groupId);

        if ($original === null || $group === null || ! $group->active) {
            return;
        }

        // Idempotent: a retried job doesn't send the member a second copy.
        $alreadySent = Message::query()
            ->where('organization_id', $original->organization_id)
            ->where('direction', 'outbound')
            ->where('meta->fanout_of', $original->id)
            ->whereNotIn('status', ['failed'])
            ->get(['id', 'to'])
            ->contains(fn (Message $copy) => in_array($this->memberEmail, (array) $copy->to, true));

        if ($alreadySent) {
            return;
        }

        $groups->deliverCopy($original, $group, $this->memberEmail, $emails);
    }
}
