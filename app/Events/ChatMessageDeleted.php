<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageDeleted implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $participantIds
     */
    public function __construct(
        public int $conversationId,
        public int $messageId,
        public array $participantIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('conversations.'.$this->conversationId),
        ];

        foreach ($this->participantIds as $userId) {
            $channels[] = new PrivateChannel('users.'.$userId.'.chat');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.deleted';
    }

    /**
     * @return array{conversation_id: int, message_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
        ];
    }
}
