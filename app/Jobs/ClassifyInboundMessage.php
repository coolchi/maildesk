<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\SmartTriageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    public function handle(SmartTriageService $triage): void
    {
        $message = Message::query()->with('thread')->find($this->messageId);

        if ($message === null || $message->direction !== 'inbound') {
            return;
        }

        $triage->classifyMessage($message);

        SendAutoReply::dispatch($message->id);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ClassifyInboundMessage failed', [
            'message_id' => $this->messageId,
            'error' => $exception?->getMessage(),
        ]);

        SendAutoReply::dispatch($this->messageId);
    }
}
