<?php

namespace App\Services\Domains;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for Resend's /domains API.
 */
class ResendDomainClient
{
    public const REGIONS = ['us-east-1', 'eu-west-1', 'sa-east-1', 'ap-northeast-1'];

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * Create the domain in Resend. If Resend says it already exists on this
     * account, adopt the existing one instead.
     *
     * @return array<string, mixed>
     */
    public function register(string $name, ?string $region = null): array
    {
        // Adopt an existing domain first. Resend happily creates a second copy
        // of the same name in another region, which silently splits the records.
        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $this->get((string) $existing['id']) ?? $existing;
        }

        $payload = ['name' => $name];

        if ($region !== null && in_array($region, self::REGIONS, true)) {
            $payload['region'] = $region;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post('/domains', $payload));

        if ($response->successful()) {
            return $this->get((string) $response->json('id')) ?? (array) $response->json();
        }

        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $this->get((string) $existing['id']) ?? $existing;
        }

        throw new RuntimeException(sprintf(
            'Resend refused to register %s (HTTP %d): %s',
            $name,
            $response->status(),
            (string) ($response->json('message') ?? 'unknown error'),
        ));
    }

    /**
     * Delete a domain Resend has left stuck and register a fresh one.
     *
     * A domain that reaches partially verified with the right DNS in place
     * does not finish on its own. A new domain, with a new DKIM key, does.
     *
     * @return array<string, mixed>
     */
    public function replace(string $oldId, string $name, ?string $region = null): array
    {
        $removed = $this->send(fn (PendingRequest $http) => $http->delete("/domains/{$oldId}"));

        if (! $removed->successful() && $removed->status() !== 404) {
            throw new RuntimeException(sprintf(
                'Resend refused to delete %s (HTTP %d): %s',
                $name,
                $removed->status(),
                (string) ($removed->json('message') ?? 'unknown error'),
            ));
        }

        $existing = $this->findByName($name);

        // The domain we just removed can linger in the list. Adopting it would
        // put us back on the stuck domain. Only adopt a different id.
        if ($existing !== null && (string) ($existing['id'] ?? '') !== $oldId) {
            return $this->get((string) $existing['id']) ?? $existing;
        }

        $payload = ['name' => $name];

        if ($region !== null && in_array($region, self::REGIONS, true)) {
            $payload['region'] = $region;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post('/domains', $payload));

        if ($response->successful()) {
            return $this->get((string) $response->json('id')) ?? (array) $response->json();
        }

        throw new RuntimeException(sprintf(
            'Resend refused to recreate %s (HTTP %d): %s',
            $name,
            $response->status(),
            (string) ($response->json('message') ?? 'unknown error'),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("/domains/{$id}"));

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * Turn on open and click tracking. A tracking subdomain is required before
     * Resend will rewrite links or count opens; "links" becomes links.example.com.
     *
     * @return array<string, mixed>|null
     */
    public function enableTracking(string $id, ?string $existingSubdomain = null): ?array
    {
        $payload = [
            'open_tracking' => true,
            'click_tracking' => true,
        ];

        if (blank($existingSubdomain)) {
            $payload['tracking_subdomain'] = 'links';
        }

        $response = $this->send(fn (PendingRequest $http) => $http->patch("/domains/{$id}", $payload));

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * Ask Resend to re-run its own DNS verification for the domain (async).
     */
    public function triggerVerify(string $id): bool
    {
        // Resend rejects a JSON array. Laravel's post() with no payload sends [],
        // so send an empty object or verification never restarts.
        return $this->send(fn (PendingRequest $http) => $http
            ->withBody('{}', 'application/json')
            ->post("/domains/{$id}/verify"))->successful();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/domains'));

        if (! $response->successful()) {
            return null;
        }

        $matches = array_values(array_filter(
            (array) $response->json('data', []),
            fn ($domain) => strcasecmp((string) ($domain['name'] ?? ''), $name) === 0,
        ));

        if ($matches === []) {
            return null;
        }

        // Several copies (different regions): prefer the most verified, then the oldest.
        $rank = fn (array $d) => match ($d['status'] ?? '') {
            'verified' => 0,
            'partially_verified' => 1,
            'pending' => 2,
            default => 3,
        };
        usort($matches, fn ($a, $b) => [$rank($a), $a['created_at'] ?? ''] <=> [$rank($b), $b['created_at'] ?? '']);

        return (array) $matches[0];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }

        $http = Http::baseUrl(rtrim((string) config('maildesk.inbound.resend_api_url', 'https://api.resend.com'), '/'))
            ->withToken((string) $this->apiKey())
            ->acceptJson()
            ->timeout(15);

        try {
            return $call($http);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Resend: '.$e->getMessage(), previous: $e);
        }
    }

    private function apiKey(): ?string
    {
        return config('maildesk.providers.resend.api_key') ?: null;
    }
}
