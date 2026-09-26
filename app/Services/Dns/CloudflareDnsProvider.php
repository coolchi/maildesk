<?php

namespace App\Services\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare DNS via an API token scoped to Zone: DNS: Edit.
 */
class CloudflareDnsProvider implements DnsProvider
{
    public function __construct(private readonly string $token) {}

    public function findZone(string $domain): array
    {
        $labels = explode('.', strtolower(trim($domain, '.')));

        // Walk up: in.desk.ng, then desk.ng.
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $name = implode('.', array_slice($labels, $i));
            $zones = $this->call(fn (PendingRequest $http) => $http->get('/zones', ['name' => $name]));

            if ($zones !== []) {
                return ['id' => (string) $zones[0]['id'], 'name' => (string) $zones[0]['name']];
            }
        }

        throw new DnsProviderException("This Cloudflare token can't see a zone for {$domain}. Give it Zone: DNS: Edit on the zone that holds this domain.");
    }

    public function records(string $zoneId): array
    {
        $rows = $this->call(fn (PendingRequest $http) => $http->get("/zones/{$zoneId}/dns_records", ['per_page' => 1000]));

        return array_values(array_map(fn (array $r) => [
            'id' => (string) $r['id'],
            'type' => strtoupper((string) $r['type']),
            'name' => strtolower((string) $r['name']),
            'content' => trim((string) $r['content'], '"'),
            'priority' => isset($r['priority']) ? (int) $r['priority'] : null,
        ], $rows));
    }

    public function create(string $zoneId, array $record): array
    {
        return $this->call(fn (PendingRequest $http) => $http->post("/zones/{$zoneId}/dns_records", array_filter([
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'priority' => $record['type'] === 'MX' ? (int) ($record['priority'] ?? 10) : null,
            'ttl' => 1,
            'proxied' => $record['type'] === 'CNAME' ? false : null,
        ], fn ($v) => $v !== null)));
    }

    public function update(string $zoneId, string $recordId, array $changes): array
    {
        return $this->call(fn (PendingRequest $http) => $http->patch("/zones/{$zoneId}/dns_records/{$recordId}", array_filter([
            'content' => $changes['content'],
            'priority' => $changes['priority'] ?? null,
        ], fn ($v) => $v !== null)));
    }

    public function delete(string $zoneId, string $recordId): void
    {
        $this->call(fn (PendingRequest $http) => $http->delete("/zones/{$zoneId}/dns_records/{$recordId}"));
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<mixed>
     */
    private function call(callable $request): array
    {
        $http = Http::baseUrl(rtrim((string) config('maildesk.dns.cloudflare_api_url', 'https://api.cloudflare.com/client/v4'), '/'))
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(15);

        try {
            $response = $request($http);
        } catch (ConnectionException) {
            throw new DnsProviderException('Could not reach Cloudflare. Try again in a moment.');
        }

        if (! $response->successful() || $response->json('success') === false) {
            $message = collect((array) $response->json('errors', []))->pluck('message')->filter()->implode(' ');

            throw new DnsProviderException('Cloudflare refused the request: '.($message ?: "HTTP {$response->status()}"));
        }

        return (array) $response->json('result', []);
    }
}
