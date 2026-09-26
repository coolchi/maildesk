<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Mail\DTO\OutboundEmail;
use App\Mail\MailManager;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\Thread;
use App\Support\AddressList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailService
{
    public function __construct(
        protected MailManager $mailManager,
        protected SignatureService $signatures,
        protected GroupAddressService $groups,
    ) {}

    /**
     * Extra payload keys:
     *  - signature (bool): append the sender's signature (default false).
     *  - expand_groups (bool): replace group addresses with their members (default true).
     *  - thread (false): don't create an inbox thread (broadcasts, group copies).
     *  - meta (array): merged into the message's meta.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, UploadedFile>  $files
     * @param  Thread|null  $thread  Continue an existing conversation instead of starting a new thread.
     */
    public function send(Organization $organization, array $payload, array $files = [], ?Thread $thread = null): Message
    {
        // Central suspension check: every send path (web, API, broadcasts) goes through here.
        app(AccountAccess::class)->assertCanSend($organization);

        $from = $this->parseAddress($payload['from']);
        $to = $this->normalizeList($payload['to'] ?? []);
        $cc = ! empty($payload['cc']) ? $this->normalizeList($payload['cc']) : [];
        $bcc = ! empty($payload['bcc']) ? $this->normalizeList($payload['bcc']) : [];

        if ($payload['expand_groups'] ?? true) {
            $to = $this->groups->expand($organization, $to);
            $cc = $this->groups->expand($organization, $cc, $to);
            $bcc = $this->groups->expand($organization, $bcc, [...$to, ...$cc]);
        }

        if ($to === [] && ($cc !== [] || $bcc !== [])) {
            $to = [array_shift($cc) ?? array_shift($bcc)];
        }

        if ($to === []) {
            throw ValidationException::withMessages([
                'to' => 'There is no one to deliver to. If you used a group address, add members to it first.',
            ]);
        }

        if (! empty($payload['signature'])) {
            $signed = $this->signatures->apply(
                $payload['html'] ?? null,
                $payload['text'] ?? null,
                $this->signatures->resolve($organization, $from['email']),
            );
            $payload['html'] = $signed['html'];
            $payload['text'] = $signed['text'];
        }

        $suppressed = $this->firstSuppressedAddress($organization, [...$to, ...$cc, ...$bcc]);
        if ($suppressed !== null) {
            return $this->createSuppressedMessage($organization, $payload, $from, $to, $suppressed, $files);
        }

        $scheduledAt = $payload['scheduled_at'] ?? null;
        $isScheduled = $scheduledAt !== null;

        $mailbox = Mailbox::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(email) = ?', [Str::lower($from['email'])])
            ->first();

        // Our own Message-ID lets customer replies thread back onto this conversation.
        $messageIdHeader = '<'.Str::uuid().'@'.(Str::after($from['email'], '@') ?: 'maildesk.local').'>';
        $headers = array_merge(['Message-ID' => $messageIdHeader], $payload['headers'] ?? []);

        $snippet = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($payload['text'] ?? $payload['html'] ?? ''))), 180);

        $threadless = ($payload['thread'] ?? true) === false;

        if ($threadless) {
            $thread = null;
        } elseif ($thread !== null) {
            $thread->forceFill([
                'mailbox_id' => $thread->mailbox_id ?? $mailbox?->id,
                'snippet' => $snippet,
                'last_message_at' => now(),
                'message_count' => $thread->messages()->count() + 1,
                'is_read' => true,
            ])->save();
        } else {
            $thread = Thread::query()->create([
                'organization_id' => $organization->id,
                'mailbox_id' => $mailbox?->id,
                'subject' => $payload['subject'],
                'snippet' => $snippet,
                'last_message_at' => now(),
                'message_count' => 1,
                'is_read' => true,
            ]);
        }

        $message = Message::query()->create([
            'organization_id' => $organization->id,
            'thread_id' => $thread?->id,
            'mailbox_id' => $mailbox?->id,
            'message_id_header' => $headers['Message-ID'],
            'in_reply_to' => $payload['in_reply_to'] ?? null,
            'references' => $payload['references'] ?? null,
            'direction' => 'outbound',
            'status' => $isScheduled ? 'scheduled' : 'queued',
            'provider' => $organization->mailProvider?->driver
                ?? $organization->default_provider,
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => $to,
            'cc' => $cc ?: null,
            'bcc' => $bcc ?: null,
            'reply_to' => isset($payload['reply_to']) ? $this->normalizeList($payload['reply_to']) : null,
            'subject' => $payload['subject'],
            'text_body' => $payload['text'] ?? null,
            'html_body' => $payload['html'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'headers' => $headers,
            'scheduled_at' => $isScheduled ? $scheduledAt : null,
            'meta' => ! empty($payload['meta']) ? (array) $payload['meta'] : null,
        ]);

        $this->storeAttachments($message, $files);

        if ($isScheduled) {
            return $message->fresh(['attachments']);
        }

        return $this->deliver($organization, $message);
    }

    public function deliver(Organization $organization, Message $message): Message
    {
        $access = app(AccountAccess::class);
        if ($access->organizationBlocked($organization)) {
            // Scheduled sends / retries of a suspended account never reach the provider.
            $message->update([
                'status' => 'failed',
                'scheduled_at' => null,
                'meta' => array_merge((array) ($message->meta ?? []), ['error' => $access->reasonFor($organization)]),
            ]);

            return $message->fresh(['attachments']);
        }

        $organization->loadMissing('mailProvider');
        $message->loadMissing('attachments');

        $attachmentPayload = $message->attachments->map(fn (Attachment $attachment) => [
            'filename' => $attachment->filename,
            'content_type' => $attachment->content_type,
            'path' => $attachment->path,
            'disk' => $attachment->disk,
        ])->values()->all();

        $sender = $this->mailManager->forOrganization($organization);

        $result = $sender->send(new OutboundEmail(
            fromEmail: $message->from_email,
            fromName: $message->from_name,
            to: $this->normalizeList($message->to ?? []),
            subject: $message->subject,
            html: $message->html_body,
            text: $message->text_body,
            cc: $message->cc,
            bcc: $message->bcc,
            replyTo: $message->reply_to,
            tags: $message->tags,
            headers: $message->headers,
            attachments: $attachmentPayload !== [] ? $attachmentPayload : null,
        ));

        $message->update([
            'status' => $result->success ? 'sent' : 'failed',
            // The driver that actually sent it (e.g. smtp when the workspace uses its own server).
            'provider' => $sender->name(),
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => $result->success ? now() : null,
            'scheduled_at' => null,
            // Merge so a retry keeps earlier history (events, previous attempts).
            'meta' => array_merge((array) ($message->meta ?? []), [
                'error' => $result->success ? null : $result->error,
                'provider_raw' => $result->raw,
            ]),
        ]);

        $message = $message->fresh(['attachments']);

        if (! $result->success) {
            // Message id, provider and error only: no credentials, no body.
            Log::warning('Email send failed', [
                'message_id' => $message->uuid,
                'organization_id' => $organization->id,
                'provider' => $message->provider,
                'broadcast_id' => $message->meta['broadcast_id'] ?? null,
                'error' => self::redactError($result->error),
            ]);
        }

        if ($result->success) {
            DispatchWebhook::dispatch($organization->id, 'email.sent', [
                'id' => $message->uuid,
                'subject' => $message->subject,
                'to' => $message->to,
                'from' => $message->from_email,
                'status' => $message->status,
            ]);
        }

        return $message;
    }

    /**
     * Provider errors can echo request details; strip anything that looks
     * like a credential before it reaches the log.
     */
    public static function redactError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $error = preg_replace('/\bre_[A-Za-z0-9_]{6,}/', 're_***', $error) ?? $error;
        $error = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $error) ?? $error;
        $error = preg_replace('/\b(password|passwd|pass|api[_-]?key|secret|token)(["\']?\s*[=:]\s*["\']?)[^\s"\',;]+/i', '$1$2***', $error) ?? $error;

        return Str::limit($error, 500);
    }

    /**
     * Resend a failed outbound message as-is (same body, recipients and
     * attachments), keeping it on its thread. Returns the updated message.
     */
    public function retry(Organization $organization, Message $message): Message
    {
        $recipients = [
            ...$this->normalizeList($message->to ?? []),
            ...$this->normalizeList($message->cc ?? []),
            ...$this->normalizeList($message->bcc ?? []),
        ];

        $suppressed = $this->firstSuppressedAddress($organization, $recipients);
        if ($suppressed !== null) {
            $message->update([
                'status' => 'suppressed',
                'meta' => array_merge((array) ($message->meta ?? []), [
                    'error' => "Recipient {$suppressed} is on the suppression list.",
                ]),
            ]);

            return $message->fresh(['attachments']);
        }

        $meta = (array) ($message->meta ?? []);
        $attempts = (array) ($meta['attempts'] ?? []);
        $attempts[] = ['at' => now()->toIso8601String(), 'error' => $meta['error'] ?? null];
        $meta['attempts'] = array_slice($attempts, -20);

        $message->forceFill(['status' => 'queued', 'meta' => $meta])->save();

        return $this->deliver($organization, $message);
    }

    /**
     * @param  array<int, string>  $addresses
     */
    protected function firstSuppressedAddress(Organization $organization, array $addresses): ?string
    {
        $normalized = array_map(fn (string $email) => Str::lower(trim($email)), $addresses);

        return Suppression::query()
            ->where('organization_id', $organization->id)
            ->whereIn('email', $normalized)
            ->value('email');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{email: string, name: ?string}  $from
     * @param  array<int, string>  $to
     * @param  array<int, UploadedFile>  $files
     */
    protected function createSuppressedMessage(
        Organization $organization,
        array $payload,
        array $from,
        array $to,
        string $suppressed,
        array $files = [],
    ): Message {
        $thread = ($payload['thread'] ?? true) === false ? null : Thread::query()->create([
            'organization_id' => $organization->id,
            'subject' => $payload['subject'],
            'snippet' => Str::limit(strip_tags($payload['text'] ?? $payload['html'] ?? ''), 180),
            'last_message_at' => now(),
            'message_count' => 1,
            'is_read' => true,
        ]);

        $message = Message::query()->create([
            'organization_id' => $organization->id,
            'thread_id' => $thread?->id,
            'direction' => 'outbound',
            'status' => 'suppressed',
            'provider' => $organization->mailProvider?->driver
                ?? $organization->default_provider,
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => $to,
            'subject' => $payload['subject'],
            'text_body' => $payload['text'] ?? null,
            'html_body' => $payload['html'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'meta' => array_merge((array) ($payload['meta'] ?? []), [
                'error' => "Recipient {$suppressed} is on the suppression list.",
            ]),
        ]);

        $this->storeAttachments($message, $files);

        return $message->fresh(['attachments']);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    protected function storeAttachments(Message $message, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('attachments/'.$message->organization_id, 'local');

            Attachment::query()->create([
                'message_id' => $message->id,
                'filename' => $file->getClientOriginalName(),
                'content_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
                'disk' => 'local',
                'path' => $path,
            ]);
        }
    }

    /**
     * @param  string|array{email: string, name?: string}  $address
     * @return array{email: string, name: ?string}
     */
    protected function parseAddress(string|array $address): array
    {
        if (is_array($address)) {
            return [
                'email' => $address['email'],
                'name' => $address['name'] ?? null,
            ];
        }

        if (preg_match('/^(.*)<(.+)>$/', $address, $matches)) {
            return [
                'email' => trim($matches[2]),
                'name' => trim($matches[1], " \t\n\r\0\x0B\""),
            ];
        }

        return ['email' => $address, 'name' => null];
    }

    /**
     * @param  string|array<int, string|array{email: string, name?: string}>  $value
     * @return array<int, string>
     */
    protected function normalizeList(string|array $value): array
    {
        $items = is_array($value) ? $value : AddressList::parse($value);

        return array_values(array_map(function ($item) {
            if (is_array($item)) {
                return $item['email'];
            }

            if (preg_match('/<(.+)>/', $item, $matches)) {
                return trim($matches[1]);
            }

            return trim($item);
        }, $items));
    }
}
