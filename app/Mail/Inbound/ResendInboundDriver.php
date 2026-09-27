<?php

namespace App\Mail\Inbound;

use App\Mail\DTO\InboundEmail;
use App\Mail\Inbound\Contracts\InboundDriver;
use App\Mail\Inbound\Exceptions\InboundRetryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Handles Resend "email.received" webhooks. Resend signs webhooks with Svix;
 * the webhook carries metadata, so the body is fetched from the receiving API
 * when it is not included, and attachments via the receiving attachments API.
 */
class ResendInboundDriver implements InboundDriver
{
    public function isConfigured(): bool
    {
        return filled(config('maildesk.inbound.resend_webhook_secret'));
    }

    public function verify(Request $request): bool
    {
        if (! $this->isConfigured()) {
            return app()->environment(['local', 'testing']);
        }

        return self::verifySvix(
            secret: (string) config('maildesk.inbound.resend_webhook_secret'),
            id: (string) $request->header('svix-id'),
            timestamp: (string) $request->header('svix-timestamp'),
            signatureHeader: (string) $request->header('svix-signature'),
            body: $request->getContent(),
            tolerance: (int) config('maildesk.inbound.signature_tolerance', 300),
        );
    }

    public static function verifySvix(
        string $secret,
        string $id,
        string $timestamp,
        string $signatureHeader,
        string $body,
        int $tolerance = 300,
    ): bool {
        if ($id === '' || $timestamp === '' || $signatureHeader === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parse(Request $request): ?InboundEmail
    {
        $payload = $request->json()->all();

        if (($payload['type'] ?? null) !== 'email.received') {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $emailId = isset($data['email_id']) ? (string) $data['email_id'] : null;

        if ($emailId !== null && empty($data['html']) && empty($data['text'])) {
            $data = array_merge($data, $this->fetchContent($emailId));
        }

        if ($emailId !== null && $this->hasAttachmentsWithoutContent($data)) {
            $data['attachments'] = $this->fetchAttachments($emailId);
        }

        return GenericInboundDriver::fromArray('resend', $data, $emailId);
    }

    /**
     * Fetch the body and headers, which the webhook does not carry.
     *
     * @return array<string, mixed>
     *
     * @throws InboundRetryException when Resend is unreachable or erroring
     */
    protected function fetchContent(string $emailId): array
    {
        $response = $this->api("emails/receiving/{$emailId}", $emailId, 'content');

        if ($response === null) {
            return [];
        }

        $body = $response->json();
        $body = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);

        return array_filter([
            'html' => $body['html'] ?? null,
            'text' => $body['text'] ?? null,
            'headers' => $body['headers'] ?? null,
            'message_id' => $body['message_id'] ?? null,
            'in_reply_to' => $body['in_reply_to'] ?? null,
            'references' => $body['references'] ?? null,
            'reply_to' => $body['reply_to'] ?? null,
            'received_for' => $body['received_for'] ?? null,
            'cc' => $body['cc'] ?? null,
            'bcc' => $body['bcc'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Webhooks only carry attachment metadata; download each file from its
     * short-lived download_url. A single failed file is skipped, not fatal.
     *
     * @return array<int, array{filename: string, content_type: string, content: string}>
     *
     * @throws InboundRetryException when the attachment list cannot be fetched
     */
    protected function fetchAttachments(string $emailId): array
    {
        $response = $this->api("emails/receiving/{$emailId}/attachments", $emailId, 'attachments');

        if ($response === null) {
            return [];
        }

        $items = $response->json('data') ?? [];
        $out = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $url = is_array($item) ? ($item['download_url'] ?? null) : null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            try {
                $file = Http::timeout(30)->get($url);
            } catch (ConnectionException $e) {
                $file = null;
            }

            if ($file === null || ! $file->successful()) {
                $this->log()->warning('Resend inbound attachment download failed', [
                    'email_id' => $emailId,
                    'attachment_id' => $item['id'] ?? null,
                    'status' => $file?->status(),
                ]);

                continue;
            }

            $out[] = [
                'filename' => (string) ($item['filename'] ?? 'attachment'),
                'content_type' => (string) ($item['content_type'] ?? 'application/octet-stream'),
                // GenericInboundDriver::fromArray expects base64 content.
                'content' => base64_encode($file->body()),
            ];
        }

        return $out;
    }

    /**
     * GET a Resend API path. Returns null when the resource is unavailable
     * for a permanent reason (no API key, 4xx); throws for retryable failures.
     */
    protected function api(string $path, string $emailId, string $what): ?Response
    {
        $key = config('services.resend.key');

        if (blank($key)) {
            $this->log()->error('Resend inbound: RESEND_API_KEY is not set, cannot fetch email '.$what, [
                'email_id' => $emailId,
            ]);

            return null;
        }

        try {
            $response = Http::withToken((string) $key)
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim((string) config('maildesk.inbound.resend_api_url'), '/').'/'.$path);
        } catch (ConnectionException $e) {
            throw new InboundRetryException("Resend API unreachable while fetching email {$what}.", previous: $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new InboundRetryException("Resend API returned {$response->status()} while fetching email {$what}.");
        }

        if (! $response->successful()) {
            $this->log()->warning('Resend inbound fetch failed', [
                'email_id' => $emailId,
                'what' => $what,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasAttachmentsWithoutContent(array $data): bool
    {
        $attachments = $data['attachments'] ?? [];

        if (! is_array($attachments) || $attachments === []) {
            return false;
        }

        foreach ($attachments as $attachment) {
            if (is_array($attachment) && empty($attachment['content'])) {
                return true;
            }
        }

        return false;
    }

    protected function log(): LoggerInterface
    {
        return Log::channel(config('maildesk.inbound.log_channel'));
    }
}
