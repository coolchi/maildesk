<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatDelivered implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public string $deliveredAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.delivered';
    }

    /**
     * @return array{conversation_id: int, user_id: int, delivered_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'delivered_at' => $this->deliveredAt,
        ];
    }
}
