<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\MobileDeviceToken;
use App\Models\User;
use App\Services\WorkspaceAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends Expo push notifications to mobile devices when new inbound messages arrive.
 */
class SendMobilePushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $messageId) {}

    public function handle(WorkspaceAccess $access): void
    {
        if (! config('maildesk.mobile.push_enabled', true)) {
            return;
        }

        $message = Message::query()
            ->with(['mailbox', 'organization'])
            ->find($this->messageId);

        if (! $message || $message->direction !== 'inbound') {
            return;
        }

        $organization = $message->organization;
        if (! $organization) {
            return;
        }

        $userIds = $organization->users()
            ->pluck('users.id')
            ->filter(fn (int $userId) => $this->canUserSeeMailbox($access, $userId, $organization, $message))
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        $tokens = MobileDeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->pluck('token')
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $this->sendPush($tokens, $message);
    }

    /**
     * @param  array<string>  $tokens
     */
    protected function sendPush(array $tokens, Message $message): void
    {
        $notifications = collect($tokens)->map(fn (string $token) => [
            'to' => $token,
            'title' => $message->from_name ?: $message->from_email,
            'body' => $message->subject ?: '(no subject)',
            'data' => [
                'thread_id' => $message->thread_id,
                'message_id' => $message->uuid,
            ],
            'sound' => 'default',
        ])->values()->all();

        $response = Http::acceptJson()
            ->timeout(30)
            ->post('https://exp.host/--/api/v2/push/send', $notifications);

        if (! $response->successful()) {
            Log::warning('SendMobilePushNotification: Expo API error', [
                'status' => $response->status(),
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $this->handleExpiredTokens($response->json('data', []));
    }

    /**
     * Remove tokens that Expo reports as DeviceNotRegistered.
     *
     * @param  array<array{status: string, message?: string, details?: array{error?: string}}>  $results
     */
    protected function handleExpiredTokens(array $results): void
    {
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $status = $result['status'] ?? '';
            $error = $result['details']['error'] ?? '';

            if ($status === 'error' && $error === 'DeviceNotRegistered') {
                $expoPushToken = $result['details']['expoPushToken'] ?? null;

                if ($expoPushToken) {
                    MobileDeviceToken::query()->where('token', $expoPushToken)->delete();

                    Log::info('SendMobilePushNotification: removed expired token', [
                        'token' => $expoPushToken,
                    ]);
                }
            }
        }
    }

    protected function canUserSeeMailbox(WorkspaceAccess $access, int $userId, $organization, Message $message): bool
    {
        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        if ($access->isTeam($user, $organization)) {
            return true;
        }

        $mailboxId = $access->scopedMailboxId($user, $organization);

        return $mailboxId === null || $mailboxId === $message->mailbox_id;
    }
}
