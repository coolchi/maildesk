<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Mail\DTO\OutboundEmail;
use App\Mail\MailManager;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\Thread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class EmailService
{
    public function __construct(
        protected MailManager $mailManager,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, UploadedFile>  $files
     */
    public function send(Organization $organization, array $payload, array $files = []): Message
    {
        $from = $this->parseAddress($payload['from']);
        $to = $this->normalizeList($payload['to'] ?? []);

        $suppressed = $this->firstSuppressedAddress($organization, $to);
        if ($suppressed !== null) {
            return $this->createSuppressedMessage($organization, $payload, $from, $to, $suppressed, $files);
        }

        $scheduledAt = $payload['scheduled_at'] ?? null;
        $isScheduled = $scheduledAt !== null;

        $thread = Thread::query()->create([
            'organization_id' => $organization->id,
            'subject' => $payload['subject'],
            'snippet' => Str::limit(strip_tags($payload['text'] ?? $payload['html'] ?? ''), 180),
            'last_message_at' => now(),
            'message_count' => 1,
            'is_read' => true,
        ]);

        $message = Message::query()->create([
            'organization_id' => $organization->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => $isScheduled ? 'scheduled' : 'queued',
            'provider' => $organization->mailProvider?->driver
                ?? $organization->default_provider,
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => $to,
            'cc' => isset($payload['cc']) ? $this->normalizeList($payload['cc']) : null,
            'bcc' => isset($payload['bcc']) ? $this->normalizeList($payload['bcc']) : null,
            'reply_to' => isset($payload['reply_to']) ? $this->normalizeList($payload['reply_to']) : null,
            'subject' => $payload['subject'],
            'text_body' => $payload['text'] ?? null,
            'html_body' => $payload['html'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'headers' => $payload['headers'] ?? null,
            'scheduled_at' => $isScheduled ? $scheduledAt : null,
        ]);

        $this->storeAttachments($message, $files);

        if ($isScheduled) {
            return $message->fresh(['attachments']);
        }

        return $this->deliver($organization, $message);
    }

    public function deliver(Organization $organization, Message $message): Message
    {
        $organization->loadMissing('mailProvider');
        $message->loadMissing('attachments');

        $attachmentPayload = $message->attachments->map(fn (Attachment $attachment) => [
            'filename' => $attachment->filename,
            'content_type' => $attachment->content_type,
            'path' => $attachment->path,
            'disk' => $attachment->disk,
        ])->values()->all();

        $result = $this->mailManager->forOrganization($organization)->send(new OutboundEmail(
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
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => $result->success ? now() : null,
            'scheduled_at' => null,
            'meta' => [
                'error' => $result->error,
                'provider_raw' => $result->raw,
            ],
        ]);

        $message = $message->fresh(['attachments']);

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
        $thread = Thread::query()->create([
            'organization_id' => $organization->id,
            'subject' => $payload['subject'],
            'snippet' => Str::limit(strip_tags($payload['text'] ?? $payload['html'] ?? ''), 180),
            'last_message_at' => now(),
            'message_count' => 1,
            'is_read' => true,
        ]);

        $message = Message::query()->create([
            'organization_id' => $organization->id,
            'thread_id' => $thread->id,
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
            'meta' => [
                'error' => "Recipient {$suppressed} is on the suppression list.",
            ],
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
        $items = is_array($value) ? $value : [$value];

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
