<?php

namespace App\Mail\Inbound;

use App\Mail\DTO\InboundEmail;
use App\Mail\Inbound\Contracts\InboundDriver;
use Illuminate\Http\Request;

/**
 * Accepts a simple JSON payload, protected by a shared secret header.
 * Useful for inbound-parse services, custom MX relays, and local testing.
 *
 * { "from": "Jane <jane@x.com>", "to": ["support@acme.com"], "subject": "...",
 *   "text": "...", "html": "...", "message_id": "<id@x>", "in_reply_to": "<...>",
 *   "references": "<a> <b>", "headers": {...},
 *   "attachments": [{"filename": "a.pdf", "content_type": "application/pdf", "content": "<base64>"}] }
 */
class GenericInboundDriver implements InboundDriver
{
    public function isConfigured(): bool
    {
        return filled(config('maildesk.inbound.generic_secret'));
    }

    public function verify(Request $request): bool
    {
        if (! $this->isConfigured()) {
            return app()->environment(['local', 'testing']);
        }

        return hash_equals(
            (string) config('maildesk.inbound.generic_secret'),
            (string) $request->header('X-MailDesk-Inbound-Secret'),
        );
    }

    public function parse(Request $request): ?InboundEmail
    {
        return self::fromArray('generic', $request->json()->all() ?: $request->all());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $provider, array $data, ?string $providerMessageId = null): ?InboundEmail
    {
        if (empty($data['from']) || empty($data['to'])) {
            return null;
        }

        $headers = self::normalizeHeaders($data['headers'] ?? []);
        $from = AddressParser::one($data['from']);

        return new InboundEmail(
            provider: $provider,
            fromEmail: $from['email'],
            fromName: $from['name'],
            to: AddressParser::many($data['to']),
            subject: (string) ($data['subject'] ?? $headers['subject'] ?? ''),
            text: $data['text'] ?? null,
            html: $data['html'] ?? null,
            cc: AddressParser::many($data['cc'] ?? []),
            replyTo: AddressParser::many($data['reply_to'] ?? $headers['reply-to'] ?? []),
            messageId: AddressParser::messageId($data['message_id'] ?? $headers['message-id'] ?? null),
            inReplyTo: AddressParser::messageId($data['in_reply_to'] ?? $headers['in-reply-to'] ?? null),
            references: AddressParser::messageIds($data['references'] ?? $headers['references'] ?? []),
            headers: $headers,
            attachments: self::normalizeAttachments($data['attachments'] ?? []),
            providerMessageId: $providerMessageId ?? (isset($data['id']) ? (string) $data['id'] : null),
            envelopeRecipients: AddressParser::many($data['envelope_to'] ?? $data['received_for'] ?? $headers['delivered-to'] ?? []),
            raw: $data,
        );
    }

    /**
     * Accepts {"Name": "value"} or [{"name": "Name", "value": "value"}]; keys lower-cased.
     *
     * @return array<string, string>
     */
    public static function normalizeHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_array($value) && isset($value['name'])) {
                $normalized[strtolower((string) $value['name'])] = (string) ($value['value'] ?? '');
            } elseif (is_string($key)) {
                $normalized[strtolower($key)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array{filename: string, content_type: string, content: string}>
     */
    protected static function normalizeAttachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $out = [];
        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || empty($attachment['content'])) {
                continue;
            }

            $decoded = base64_decode((string) $attachment['content'], true);
            if ($decoded === false) {
                continue;
            }

            $out[] = [
                'filename' => basename((string) ($attachment['filename'] ?? 'attachment')),
                'content_type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                'content' => $decoded,
            ];
        }

        return $out;
    }
}
