<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AutoReplyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAutoReply implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId) {}

    public function handle(AutoReplyService $autoReply): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null) {
            return;
        }

        $autoReply->acknowledge($message);
    }
}
