<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Push\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SendMailPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public int $timeout = 15;

    public function __construct(public int $messageId) {}

    public function handle(FcmClient $fcm): void
    {
        if (! $fcm->enabled()) {
            return;
        }

        $message = Message::query()
            ->with(['thread:id,subject,snippet'])
            ->find($this->messageId);

        if ($message === null || $message->direction !== 'inbound') {
            return;
        }

        $organization = Organization::query()->find($message->organization_id);

        if ($organization === null) {
            return;
        }

        $tokens = DeviceToken::query()
            ->whereIn('user_id', $organization->users()->select('users.id'))
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return;
        }

        $from = trim((string) ($message->from_name ?: $message->from_email)) ?: 'New mail';
        $subject = trim((string) ($message->subject ?: $message->thread?->subject)) ?: '(no subject)';
        $snippet = trim((string) ($message->thread?->snippet ?? ''));
        $body = $snippet !== '' ? $subject.' — '.Str::limit($snippet, 100) : $subject;

        $fcm->send(
            $tokens,
            [
                'title' => $from,
                'body' => Str::limit($body, 140),
            ],
            [
                'type' => 'mail',
                'thread_id' => (string) ($message->thread_id ?? ''),
                'organization_id' => (string) $message->organization_id,
            ],
        );
    }
}
