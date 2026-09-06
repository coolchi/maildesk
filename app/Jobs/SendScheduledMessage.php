<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\EmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledMessage implements ShouldQueue
{
    use Queueable;

    public function handle(EmailService $emails): void
    {
        Message::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get()
            ->each(function (Message $message) use ($emails): void {
                $organization = $message->organization()->with('mailProvider')->first();

                if (! $organization) {
                    return;
                }

                $emails->deliver($organization, $message);
            });
    }
}
