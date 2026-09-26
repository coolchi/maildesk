<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Monipay API (https://monipay.ng/api-docs).
 *
 * - initialize: POST /transaction/initialize with the PUBLIC key.
 * - verify:     GET  /transaction/verify/{reference} with the PRIVATE key.
 *
 * Monipay responses may carry their fields at the top level or nested under
 * `data`; the static helpers below normalise both shapes.
 */
class MonipayClient
{
    /** Status values that mean the charge succeeded (data.status, compared case-insensitively). */
    public const SUCCESS_STATUSES = ['success', 'approved'];

    /** Status values we treat as "not final yet" (payment stays pending). */
    public const PENDING_STATUSES = ['pending', 'processing', 'ongoing', 'initialized', 'initiated', 'queued', ''];

    public const ABANDONED_STATUSES = ['abandoned'];

    public function isConfigured(): bool
    {
        return filled($this->publicKey()) && filled($this->secretKey());
    }

    public function publicKey(): ?string
    {
        return config('services.monipay.public_key') ?: null;
    }

    public function webhookSecret(): ?string
    {
        return config('services.monipay.webhook_secret') ?: $this->secretKey();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     *
     * @throws ConnectionException
     */
    public function initialize(array $payload): array
    {
        $key = $this->publicKey() ?? throw new RuntimeException('Monipay public key is not configured.');

        return $this->wrap($this->request($key)->post('/transaction/initialize', $payload));
    }

    /**
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     *
     * @throws ConnectionException
     */
    public function verify(string $reference): array
    {
        $key = $this->secretKey() ?? throw new RuntimeException('Monipay secret key is not configured.');

        return $this->wrap($this->request($key)->get('/transaction/verify/'.rawurlencode($reference)));
    }

    /**
     * Hex HMAC-SHA512 of the raw body, compared in constant time.
     */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->webhookSecret();

        if (! $secret || ! is_string($signature) || $signature === '') {
            return false;
        }

        // One doc example shows a "sha512=" prefix; accept it optionally.
        $signature = strtolower(trim($signature));
        if (str_starts_with($signature, 'sha512=')) {
            $signature = substr($signature, 7);
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /**
     * Read a field from `data.*` first, then the top level.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    public static function field(array $body, array $keys): mixed
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        foreach ([$data, $body] as $source) {
            foreach ($keys as $key) {
                $value = data_get($source, $key);
                if ($value !== null && $value !== '' && ! is_array($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The transaction status string. A top-level boolean `status` (request
     * succeeded) is ignored; only string statuses count.
     *
     * @param  array<string, mixed>  $body
     */
    public static function transactionStatus(array $body): string
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        foreach ([$data, $body] as $source) {
            foreach (['status', 'transaction_status', 'payment_status'] as $key) {
                if (isset($source[$key]) && is_string($source[$key]) && $source[$key] !== '') {
                    return strtolower(trim($source[$key]));
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function isSuccessStatus(array $body): bool
    {
        return in_array(self::transactionStatus($body), self::SUCCESS_STATUSES, true);
    }

    /**
     * Amount in kobo, or null when absent / not an integer value.
     *
     * @param  array<string, mixed>  $body
     */
    public static function amount(array $body): ?int
    {
        $amount = self::field($body, ['amount']);

        if (is_int($amount)) {
            return $amount;
        }

        if (is_string($amount) && preg_match('/^\d+$/', $amount)) {
            return (int) $amount;
        }

        if (is_float($amount) && floor($amount) === $amount) {
            return (int) $amount;
        }

        return null;
    }

    /**
     * Fields worth keeping from a verify response. Anything else (card data,
     * authorization objects, customer details) is dropped.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function safePayload(array $body): array
    {
        $keep = ['status', 'transaction_status', 'payment_status', 'message', 'amount', 'currency', 'reference',
            'order_id', 'trans_id', 'id', 'channel', 'paid_at', 'paidAt', 'created_at', 'gateway_response', 'fees'];

        $pick = fn (array $source) => collect($source)
            ->only($keep)
            ->filter(fn ($value) => is_scalar($value) || $value === null)
            ->all();

        return array_filter([
            'top' => $pick($body),
            'data' => is_array($body['data'] ?? null) ? $pick($body['data']) : null,
        ]);
    }

    protected function secretKey(): ?string
    {
        return config('services.monipay.secret_key') ?: null;
    }

    protected function request(string $key): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.monipay.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.monipay.timeout', 20))
            ->connectTimeout(10);
    }

    /**
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    protected function wrap(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // Documented error shape is {"status": false, ...}; live errors have
        // been seen as {"success": false, ...}. Either (or non-2xx) = failure.
        $ok = $response->successful()
            && ($body['status'] ?? null) !== false
            && ($body['success'] ?? null) !== false;

        return [
            'ok' => $ok,
            'status' => $response->status(),
            'body' => $body,
        ];
    }
}
