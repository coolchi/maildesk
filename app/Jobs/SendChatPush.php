<?php

namespace App\Jobs;

use App\Enums\ConversationType;
use App\Models\ChatMessage;
use App\Models\ConversationParticipant;
use App\Models\DeviceToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendChatPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public int $timeout = 15;

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $key = config('services.fcm.server_key');

        if (! is_string($key) || $key === '') {
            return;
        }

        $message = ChatMessage::query()->with(['user:id,name', 'conversation'])->find($this->messageId);

        if ($message === null || $message->conversation === null) {
            return;
        }

        $userIds = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', '!=', $message->user_id)
            ->pluck('user_id');

        $tokens = DeviceToken::query()->whereIn('user_id', $userIds)->pluck('token');

        if ($tokens->isEmpty()) {
            return;
        }

        $isGroup = $message->conversation->type === ConversationType::Group;
        $sender = $message->user?->name ?? 'MailDesk';
        $title = $isGroup ? ($message->conversation->name ?: 'Group') : $sender;
        $body = $isGroup ? $sender.': '.$message->body : $message->body;

        Http::withHeaders([
            'Authorization' => 'key='.$key,
        ])->timeout(5)->post('https://fcm.googleapis.com/fcm/send', [
            'registration_ids' => $tokens->all(),
            'priority' => 'high',
            'notification' => [
                'title' => $title,
                'body' => Str::limit($body, 140),
            ],
            'data' => [
                'type' => 'chat',
                'conversation_id' => (string) $message->conversation_id,
            ],
        ])->throw();
    }
}
