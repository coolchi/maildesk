<?php

namespace App\Mail\Inbound;

use App\Jobs\ProcessResendInboundEmail;
use App\Mail\DTO\InboundEmail;
use App\Mail\Inbound\Contracts\InboundDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Handles Resend "email.received" webhooks. Resend signs webhooks with Svix.
 *
 * The webhook only carries metadata (from, to, subject, email_id). The full
 * content including received_for (needed for routing) must be fetched from
 * Resend's receiving API. To avoid webhook timeouts, we:
 *
 * 1. Verify the signature
 * 2. Dedupe by provider email_id
 * 3. Dispatch ProcessResendInboundEmail job
 * 4. Return 202 Accepted quickly
 *
 * The job fetches the content, routes using received_for/to/cc, creates the
 * message and thread, and handles attachments.
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

    /**
     * Parse the webhook and dispatch async processing.
     *
     * Returns null if this is not an email.received event (so the controller
     * can check for delivery events). The actual email processing happens
     * in ProcessResendInboundEmail job.
     */
    public function parse(Request $request): ?InboundEmail
    {
        $payload = $request->json()->all();

        if (($payload['type'] ?? null) !== 'email.received') {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $emailId = isset($data['email_id']) ? (string) $data['email_id'] : null;

        if ($emailId === null) {
            $this->log()->warning('Resend inbound: email.received without email_id', $data);

            return null;
        }

        if ($this->isDuplicate($emailId)) {
            $this->log()->info('Resend inbound: duplicate email_id ignored', ['email_id' => $emailId]);

            return $this->createDummyEmail($emailId);
        }

        $this->markSeen($emailId);

        ProcessResendInboundEmail::dispatch($emailId, $data);

        $this->log()->info('Resend inbound: processing queued', [
            'email_id' => $emailId,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? [],
            'subject' => $data['subject'] ?? null,
        ]);

        return $this->createDummyEmail($emailId);
    }

    /**
     * Check if we've already seen this email_id (dedupe on webhook retries).
     */
    protected function isDuplicate(string $emailId): bool
    {
        return Cache::has("resend_inbound:{$emailId}");
    }

    /**
     * Mark this email_id as seen for 24 hours (dedupe window).
     */
    protected function markSeen(string $emailId): void
    {
        Cache::put("resend_inbound:{$emailId}", true, now()->addHours(24));
    }

    /**
     * Create a dummy InboundEmail that signals async processing.
     *
     * The controller checks deferredProcessing to return 202 Accepted.
     */
    protected function createDummyEmail(string $emailId): InboundEmail
    {
        $email = new InboundEmail(
            provider: 'resend',
            fromEmail: '',
            fromName: null,
            to: [],
        );
        $email->deferredProcessing = true;
        $email->providerMessageId = $emailId;

        return $email;
    }

    protected function log(): LoggerInterface
    {
        return Log::channel(config('maildesk.inbound.log_channel'));
    }
}
