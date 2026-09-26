<?php

namespace App\Services\Webhooks;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends one attempt of a webhook delivery through the SSRF-safe path:
 * the URL is re-validated and DNS-resolved, the connection is pinned to the
 * vetted IP, redirects are not followed and the timeout is short.
 *
 * Signing (see /docs):
 *  - X-MailDesk-Signature:    hex HMAC-SHA256 of the raw body (legacy, unchanged)
 *  - X-MailDesk-Timestamp:    unix seconds when this attempt was sent
 *  - X-MailDesk-Signature-V2: hex HMAC-SHA256 of "{timestamp}.{raw body}"
 */
class WebhookDeliverer
{
    public const TIMEOUT_SECONDS = 5;

    public const CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /**
     * @return array{ok: bool, retryable: bool, status: ?int, error: ?string}
     */
    public function attempt(WebhookDelivery $delivery): array
    {
        /** @var Webhook|null $webhook */
        $webhook = $delivery->webhook;

        $delivery->forceFill(['attempts' => (int) $delivery->attempts + 1])->save();

        if ($webhook === null) {
            return $this->fail($delivery, null, null, 'Webhook endpoint no longer exists.', false);
        }

        $body = (string) json_encode($delivery->payload);
        $timestamp = (string) now()->getTimestamp();

        try {
            $target = $this->guard->resolve($webhook->url);
        } catch (BlockedWebhookUrlException $e) {
            return $this->fail($delivery, $webhook, null, 'Blocked: '.$e->getMessage(), false);
        }

        $pin = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$pin}"]]])
                ->withHeaders([
                    'User-Agent' => 'MailDesk-Webhooks/1.0',
                    'X-MailDesk-Event' => $delivery->event,
                    'X-MailDesk-Delivery' => (string) $delivery->id,
                    'X-MailDesk-Signature' => self::sign($body, (string) $webhook->secret),
                    'X-MailDesk-Timestamp' => $timestamp,
                    'X-MailDesk-Signature-V2' => self::sign($timestamp.'.'.$body, (string) $webhook->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        } catch (ConnectionException|RequestException $e) {
            return $this->fail($delivery, $webhook, null, Str::limit($e->getMessage(), 500), true);
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'response_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 2000),
                'status' => 'success',
                'delivered_at' => now(),
            ])->save();

            return ['ok' => true, 'retryable' => false, 'status' => $response->status(), 'error' => null];
        }

        $status = $response->status();
        $error = $response->redirect()
            ? "Endpoint answered with a redirect (HTTP {$status}); redirects are not followed."
            : "Endpoint answered HTTP {$status}.";

        $delivery->forceFill(['response_body' => Str::limit($response->body(), 2000)]);

        // 4xx (except 408/429) are the receiver's decision; retrying won't help.
        $retryable = $status >= 500 || in_array($status, [408, 429], true);

        return $this->fail($delivery, $webhook, $status, $error, $retryable, keepBody: true);
    }

    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @return array{ok: bool, retryable: bool, status: ?int, error: ?string}
     */
    private function fail(WebhookDelivery $delivery, ?Webhook $webhook, ?int $status, string $error, bool $retryable, bool $keepBody = false): array
    {
        $delivery->forceFill([
            'response_status' => $status,
            'response_body' => $keepBody && $delivery->response_body ? $delivery->response_body : $error,
            'status' => 'failed',
            'delivered_at' => now(),
        ])->save();

        // Never log the secret or the payload.
        Log::warning('Webhook delivery attempt failed', [
            'webhook_id' => $webhook?->id ?? $delivery->webhook_id,
            'delivery_id' => $delivery->id,
            'event' => $delivery->event,
            'attempt' => (int) $delivery->attempts,
            'status' => $status,
            'error' => $error,
        ]);

        return ['ok' => false, 'retryable' => $retryable, 'status' => $status, 'error' => $error];
    }
}
