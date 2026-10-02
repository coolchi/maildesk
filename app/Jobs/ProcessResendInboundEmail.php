<?php

namespace App\Jobs;

use App\Events\InboxUpdated;
use App\Mail\DTO\InboundEmail;
use App\Mail\Inbound\GenericInboundDriver;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Services\GroupAddressService;
use App\Services\SmartTriageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes a Resend inbound email asynchronously.
 *
 * When the Resend webhook arrives, it only contains metadata. This job:
 * 1. Fetches the full email content from Resend's receiving API
 * 2. Routes the email using received_for, then to/cc/bcc
 * 3. Creates the message and thread
 * 4. Downloads and stores attachments
 *
 * This approach avoids webhook timeouts and allows proper routing based on
 * the received_for field which is only available from the API.
 */
class ProcessResendInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    /**
     * @param  array<string, mixed>  $webhookData  The data from the webhook payload
     */
    public function __construct(
        public string $emailId,
        public array $webhookData = [],
    ) {}

    public function handle(): void
    {
        $key = config('services.resend.key');

        if (blank($key)) {
            throw new \RuntimeException('ProcessResendInboundEmail: RESEND_API_KEY is not set');
        }

        $apiUrl = rtrim((string) config('maildesk.inbound.resend_api_url', 'https://api.resend.com'), '/');

        $content = $this->fetchContent($key, $apiUrl);

        if ($content === null) {
            return;
        }

        $mergedData = array_merge($this->webhookData, $content);
        $email = GenericInboundDriver::fromArray('resend', $mergedData, $this->emailId);

        if ($email === null) {
            Log::warning('ProcessResendInboundEmail: could not parse email', [
                'email_id' => $this->emailId,
            ]);

            return;
        }

        [$organization, $mailbox] = $this->resolveRecipient($email);

        if ($organization === null) {
            Log::info('ProcessResendInboundEmail: no matching mailbox or domain', [
                'email_id' => $this->emailId,
                'recipients' => $email->recipients(),
                'from' => $email->fromEmail,
            ]);

            return;
        }

        if ($existing = $this->findDuplicate($organization, $email)) {
            Log::info('ProcessResendInboundEmail: duplicate ignored', [
                'email_id' => $this->emailId,
                'existing_message_id' => $existing->id,
            ]);

            return;
        }

        $message = DB::transaction(function () use ($organization, $mailbox, $email) {
            $thread = $this->findThread($organization, $mailbox, $email)
                ?? Thread::query()->create([
                    'organization_id' => $organization->id,
                    'mailbox_id' => $mailbox?->id,
                    'subject' => $email->subject !== '' ? $email->subject : '(no subject)',
                    'message_count' => 0,
                    'is_read' => false,
                ]);

            $message = Message::query()->create([
                'organization_id' => $organization->id,
                'thread_id' => $thread->id,
                'mailbox_id' => $mailbox?->id ?? $thread->mailbox_id,
                'direction' => 'inbound',
                'status' => 'received',
                'provider' => $email->provider,
                'provider_message_id' => $email->providerMessageId,
                'message_id_header' => $email->messageId,
                'in_reply_to' => $email->inReplyTo,
                'references' => $email->references ?: null,
                'from_email' => $email->fromEmail,
                'from_name' => $email->fromName,
                'to' => $email->to,
                'cc' => $email->cc ?: null,
                'reply_to' => $email->replyTo ?: null,
                'subject' => $email->subject,
                'text_body' => $email->text,
                'html_body' => $email->html,
                'headers' => $email->headers ?: null,
                'received_at' => now(),
            ]);

            $this->storeAttachments($message, $email);

            $thread->forceFill([
                'mailbox_id' => $thread->mailbox_id ?? $mailbox?->id,
                'snippet' => $this->snippet($email),
                'last_message_at' => now(),
                'message_count' => $thread->messages()->count(),
                'is_read' => false,
                'is_trashed' => false,
                'trashed_at' => null,
                'is_archived' => false,
            ])->save();

            return $message;
        });

        DispatchWebhook::dispatch($organization->id, 'email.received', [
            'id' => $message->uuid,
            'thread_id' => $message->thread_id,
            'mailbox' => $mailbox?->email,
            'from' => $message->from_email,
            'to' => $message->to,
            'subject' => $message->subject,
            'status' => $message->status,
        ]);

        InboxUpdated::dispatch($organization, $mailbox?->id ?? $message->mailbox_id);
        SendMailPush::dispatch($message->id)->afterCommit();

        app(GroupAddressService::class)->routeInbound($message, $email->recipients());

        if (app(SmartTriageService::class)->shouldTriage()) {
            ClassifyInboundMessage::dispatch($message->id);
        }

        $this->fetchAndStoreAttachments($message, $key, $apiUrl);

        Log::info('ProcessResendInboundEmail: email received', [
            'email_id' => $this->emailId,
            'message_id' => $message->id,
            'organization_id' => $organization->id,
            'thread_id' => $message->thread_id,
        ]);
    }

    /**
     * Fetch email content from Resend's receiving API.
     *
     * @return array<string, mixed>|null Returns null only for permanent failures (404 - email not found)
     *
     * @throws \RuntimeException For retryable failures (5xx, 429, 401, 403)
     * @throws ConnectionException For network failures
     */
    protected function fetchContent(string $key, string $apiUrl): ?array
    {
        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(30)
                ->get("{$apiUrl}/emails/receiving/{$this->emailId}");
        } catch (ConnectionException $e) {
            throw $e;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new \RuntimeException("Resend API returned {$response->status()}");
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException("Resend API authentication failed ({$response->status()}): check RESEND_API_KEY");
        }

        if ($response->status() === 404) {
            Log::warning('ProcessResendInboundEmail: email not found (404), dropping permanently', [
                'email_id' => $this->emailId,
            ]);

            return null;
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Resend API returned unexpected status {$response->status()}");
        }

        $body = $response->json();

        return is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);
    }

    /**
     * @return array{0: ?Organization, 1: ?Mailbox}
     */
    protected function resolveRecipient(InboundEmail $email): array
    {
        $recipients = $email->recipients();
        if ($recipients === []) {
            return [null, null];
        }

        $mailboxes = Mailbox::query()
            ->with('organization')
            ->whereIn(DB::raw('lower(email)'), $recipients)
            ->where('status', 'active')
            ->where('inbox', true)
            ->get()
            ->sortBy(fn (Mailbox $m) => array_search(Str::lower($m->email), $recipients, true));

        if ($mailbox = $mailboxes->first()) {
            return [$mailbox->organization, $mailbox];
        }

        $domains = array_values(array_unique(array_map(
            fn (string $address) => Str::after($address, '@'),
            $recipients,
        )));

        $domain = Domain::query()
            ->with('organization')
            ->whereIn(DB::raw('lower(name)'), $domains)
            ->first();

        return [$domain?->organization, null];
    }

    protected function findDuplicate(Organization $organization, InboundEmail $email): ?Message
    {
        if ($email->messageId === null && $email->providerMessageId === null) {
            return null;
        }

        return Message::query()
            ->where('organization_id', $organization->id)
            ->where('direction', 'inbound')
            ->where(function ($query) use ($email) {
                if ($email->messageId !== null) {
                    $query->orWhere('message_id_header', $email->messageId);
                }
                if ($email->providerMessageId !== null) {
                    $query->orWhere(fn ($q) => $q
                        ->where('provider', $email->provider)
                        ->where('provider_message_id', $email->providerMessageId));
                }
            })
            ->first();
    }

    protected function findThread(Organization $organization, ?Mailbox $mailbox, InboundEmail $email): ?Thread
    {
        $ids = $email->referencedMessageIds();

        if ($ids !== []) {
            $related = Message::query()
                ->where('organization_id', $organization->id)
                ->whereIn('message_id_header', $ids)
                ->get(['thread_id', 'message_id_header'])
                ->sortBy(fn (Message $m) => array_search($m->message_id_header, $ids, true))
                ->first();

            if ($related) {
                return Thread::query()->where('organization_id', $organization->id)->find($related->thread_id);
            }
        }

        $subject = $this->normalizeSubject($email->subject);
        $isReply = $subject !== Str::lower(trim($email->subject));

        if ($subject === '' || ! $isReply) {
            return null;
        }

        return Thread::query()
            ->where('organization_id', $organization->id)
            ->when($mailbox, fn ($q) => $q->where('mailbox_id', $mailbox->id))
            ->where('last_message_at', '>=', now()->subDays(30))
            ->whereHas('messages', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('from_email', $email->fromEmail)
                ->orWhereJsonContains('to', $email->fromEmail)))
            ->latest('last_message_at')
            ->get()
            ->first(fn (Thread $t) => $this->normalizeSubject($t->subject) === $subject);
    }

    protected function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        while (preg_match('/^(re|fw|fwd|aw|sv)\s*(\[\d+\])?\s*:\s*/i', $subject)) {
            $subject = trim(preg_replace('/^(re|fw|fwd|aw|sv)\s*(\[\d+\])?\s*:\s*/i', '', $subject));
        }

        return Str::lower($subject);
    }

    protected function snippet(InboundEmail $email): string
    {
        $text = $email->text ?? strip_tags((string) $email->html);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $text)), 180);
    }

    protected function storeAttachments(Message $message, InboundEmail $email): void
    {
        $disk = (string) config('maildesk.inbound.attachments_disk', 'local');

        foreach ($email->attachments as $attachment) {
            $filename = $attachment['filename'] !== '' ? $attachment['filename'] : 'attachment';
            $path = 'attachments/'.$message->organization_id.'/inbound/'.Str::uuid().'-'.$filename;

            Storage::disk($disk)->put($path, $attachment['content']);

            Attachment::query()->create([
                'message_id' => $message->id,
                'filename' => $filename,
                'content_type' => $attachment['content_type'],
                'size' => strlen($attachment['content']),
                'disk' => $disk,
                'path' => $path,
            ]);
        }
    }

    protected function fetchAndStoreAttachments(Message $message, string $key, string $apiUrl): void
    {
        if ($message->attachments()->exists()) {
            return;
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(30)
                ->get("{$apiUrl}/emails/receiving/{$this->emailId}/attachments");
        } catch (ConnectionException) {
            return;
        }

        if (! $response->successful()) {
            return;
        }

        $items = $response->json('data') ?? [];
        $disk = (string) config('maildesk.inbound.attachments_disk', 'local');

        foreach (is_array($items) ? $items : [] as $item) {
            $url = is_array($item) ? ($item['download_url'] ?? null) : null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            try {
                $file = Http::timeout(30)->get($url);
            } catch (ConnectionException) {
                continue;
            }

            if (! $file->successful()) {
                continue;
            }

            $filename = (string) ($item['filename'] ?? 'attachment');
            $path = 'attachments/'.$message->organization_id.'/inbound/'.Str::uuid().'-'.$filename;

            Storage::disk($disk)->put($path, $file->body());

            Attachment::query()->create([
                'message_id' => $message->id,
                'filename' => $filename,
                'content_type' => (string) ($item['content_type'] ?? 'application/octet-stream'),
                'size' => strlen($file->body()),
                'disk' => $disk,
                'path' => $path,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ProcessResendInboundEmail failed', [
            'email_id' => $this->emailId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
