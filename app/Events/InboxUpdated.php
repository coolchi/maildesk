<?php

namespace App\Events;

use App\Models\Organization;
use App\Support\InboxSyncState;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the workspace inbox channel when new mail is stored.
 * Uses ShouldBroadcastNow so clients hear it even if the queue worker is idle.
 * ShouldRescue keeps inbound webhooks healthy when Reverb is offline (clients poll).
 */
class InboxUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public int $unread;

    public string $cursor;

    public function __construct(
        public Organization $organization,
        public ?int $mailboxId = null,
    ) {
        $state = InboxSyncState::for($organization);
        $this->unread = $state['unread'];
        $this->cursor = $state['cursor'];
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organizations.'.$this->organization->id.'.inbox'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    /**
     * @return array{unread: int, cursor: string, organization_id: int, mailbox_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'mailbox_id' => $this->mailboxId,
            'unread' => $this->unread,
            'cursor' => $this->cursor,
        ];
    }
}
