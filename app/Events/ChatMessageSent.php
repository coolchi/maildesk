<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $participantIds
     */
    public function __construct(
        public ChatMessage $message,
        public array $participantIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('conversations.'.$this->message->conversation_id),
        ];

        foreach ($this->participantIds as $userId) {
            $channels[] = new PrivateChannel('users.'.$userId.'.chat');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * @return array{message: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $this->message->loadMissing('user:id,name');

        return [
            'message' => $this->message->toAppArray(),
        ];
    }
}
