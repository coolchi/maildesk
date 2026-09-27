<?php

namespace App\Jobs;

use App\Events\InboxUpdated;
use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches the body and attachments for a Resend inbound email asynchronously.
 *
 * When the Resend webhook arrives, it only contains metadata. The actual body
 * and attachments must be fetched from Resend's receiving API. This job runs
 * that fetch in the background to avoid webhook timeouts.
 */
class FetchInboundEmailBody implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(
        public int $messageId,
        public string $emailId,
    ) {}

    public function handle(): void
    {
        $message = Message::query()->find($this->messageId);

        if ($message === null || $message->provider !== 'resend') {
            return;
        }

        if ($this->hasContent($message)) {
            return;
        }

        $key = config('services.resend.key');

        if (blank($key)) {
            Log::warning('FetchInboundEmailBody: RESEND_API_KEY not set', [
                'message_id' => $this->messageId,
                'email_id' => $this->emailId,
            ]);

            return;
        }

        $apiUrl = rtrim((string) config('maildesk.inbound.resend_api_url', 'https://api.resend.com'), '/');

        $content = $this->fetchContent($key, $apiUrl);

        if ($content !== null) {
            $message->update([
                'html_body' => $content['html'] ?? $message->html_body,
                'text_body' => $content['text'] ?? $message->text_body,
            ]);

            if (! empty($content['headers'])) {
                $this->updateThreadingHeaders($message, $content);
            }
        }

        $this->fetchAndStoreAttachments($message, $key, $apiUrl);

        InboxUpdated::dispatch($message->organization, $message->mailbox_id);
    }

    protected function hasContent(Message $message): bool
    {
        return filled($message->html_body) || filled($message->text_body);
    }

    /**
     * @return array<string, mixed>|null
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

        if (! $response->successful()) {
            Log::warning('FetchInboundEmailBody: content fetch failed', [
                'email_id' => $this->emailId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();
        $body = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);

        return $body;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    protected function updateThreadingHeaders(Message $message, array $content): void
    {
        $updates = [];

        if (isset($content['in_reply_to']) && blank($message->in_reply_to)) {
            $updates['in_reply_to'] = $content['in_reply_to'];
        }

        if (isset($content['references']) && blank($message->references)) {
            $updates['references'] = $content['references'];
        }

        if (isset($content['message_id']) && blank($message->message_id_header)) {
            $updates['message_id_header'] = $content['message_id'];
        }

        if (! empty($content['headers']) && blank($message->headers)) {
            $updates['headers'] = $content['headers'];
        }

        if (! empty($updates)) {
            $message->update($updates);
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
        Log::warning('FetchInboundEmailBody failed', [
            'message_id' => $this->messageId,
            'email_id' => $this->emailId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
