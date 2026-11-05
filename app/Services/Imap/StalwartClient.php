<?php

namespace App\Services\Imap;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Thin JMAP management client for Stalwart (urn:stalwart:jmap).
 */
class StalwartClient
{
    public function enabled(): bool
    {
        return (bool) config('maildesk.stalwart.enabled')
            && filled(config('maildesk.stalwart.url'))
            && filled(config('maildesk.stalwart.admin_user'))
            && filled(config('maildesk.stalwart.admin_password'));
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>, 2: string}>  $calls
     * @return array<string, mixed>
     */
    public function call(array $calls): array
    {
        $response = $this->http()->post('/jmap', [
            'using' => ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'],
            'methodCalls' => $calls,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Stalwart JMAP HTTP '.$response->status().': '.$response->body());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findDomain(string $name): ?array
    {
        $name = Str::lower($name);
        $result = $this->call([['x:Domain/get', ['ids' => null, 'properties' => ['name', 'id']], 'd0']]);
        $list = $result['methodResponses'][0][1]['list'] ?? [];

        foreach ($list as $row) {
            if (Str::lower((string) ($row['name'] ?? '')) === $name) {
                return ['id' => (string) $row['id'], 'name' => (string) $row['name']];
            }
        }

        return null;
    }

    /**
     * @return array{id: string, name: string}
     */
    public function ensureDomain(string $name): array
    {
        if ($existing = $this->findDomain($name)) {
            return $existing;
        }

        $result = $this->call([['x:Domain/set', [
            'create' => [
                'new' => [
                    'name' => Str::lower($name),
                    'enable' => true,
                    'certificateManagement' => ['@type' => 'Manual'],
                    'dkimManagement' => ['@type' => 'Automatic'],
                    'dnsManagement' => ['@type' => 'Manual'],
                    'subAddressing' => ['@type' => 'Enabled'],
                ],
            ],
        ], 'd1']]);

        $created = $result['methodResponses'][0][1]['created']['new'] ?? null;
        if (! is_array($created) || empty($created['id'])) {
            $err = $result['methodResponses'][0][1]['notCreated']['new'] ?? $result;
            throw new RuntimeException('Stalwart domain create failed: '.json_encode($err));
        }

        return ['id' => (string) $created['id'], 'name' => Str::lower($name)];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findAccount(string $localPart, string $domainId): ?array
    {
        $local = Str::lower($localPart);
        $result = $this->call([['x:Account/get', ['ids' => null, 'properties' => ['name', 'id', 'domainId', '@type']], 'a0']]);
        $list = $result['methodResponses'][0][1]['list'] ?? [];

        foreach ($list as $row) {
            if (($row['@type'] ?? '') !== 'User') {
                continue;
            }
            if (Str::lower((string) ($row['name'] ?? '')) !== $local) {
                continue;
            }
            if (isset($row['domainId']) && (string) $row['domainId'] !== (string) $domainId) {
                continue;
            }

            return ['id' => (string) $row['id'], 'name' => (string) $row['name']];
        }

        return null;
    }

    /**
     * Create or update a user mailbox account and set its password credential.
     *
     * @return array{id: string, domain_id: string}
     */
    public function upsertMailboxUser(string $email, string $password, ?string $existingAccountId = null): array
    {
        [$local, $domainName] = $this->splitEmail($email);
        $domain = $this->ensureDomain($domainName);

        $accountId = $existingAccountId;
        if (! $accountId) {
            $found = $this->findAccount($local, $domain['id']);
            $accountId = $found['id'] ?? null;
        }

        if (! $accountId) {
            $accountId = $this->createUser($local, $domain['id']);
        }

        try {
            $this->setPassword($accountId, $password);
        } catch (RuntimeException $e) {
            // Stale MailDesk account id (e.g. after Stalwart volume wipe).
            if (! str_contains($e->getMessage(), 'notFound')) {
                throw $e;
            }
            $found = $this->findAccount($local, $domain['id']);
            $accountId = $found['id'] ?? $this->createUser($local, $domain['id']);
            $this->setPassword($accountId, $password);
        }

        return ['id' => $accountId, 'domain_id' => $domain['id']];
    }

    protected function createUser(string $local, string $domainId): string
    {
        $result = $this->call([['x:Account/set', [
            'create' => [
                'new' => [
                    '@type' => 'User',
                    'name' => $local,
                    'domainId' => $domainId,
                    'roles' => ['@type' => 'User'],
                    'permissions' => ['@type' => 'Inherit'],
                    'encryptionAtRest' => ['@type' => 'Disabled'],
                ],
            ],
        ], 'a1']]);

        $created = $result['methodResponses'][0][1]['created']['new'] ?? null;
        if (! is_array($created) || empty($created['id'])) {
            $err = $result['methodResponses'][0][1]['notCreated']['new'] ?? $result;
            throw new RuntimeException('Stalwart account create failed: '.json_encode($err));
        }

        return (string) $created['id'];
    }

    public function setPassword(string $accountId, string $password): void
    {
        // Stalwart expects credentials as a JSON object map {"0":{...}}.
        // A PHP array with key "0" would json_encode as a list — cast to object.
        $result = $this->call([['x:Account/set', [
            'update' => [
                $accountId => [
                    'credentials' => (object) [
                        '0' => [
                            '@type' => 'Password',
                            'secret' => $password,
                        ],
                    ],
                ],
            ],
        ], 'a2']]);

        $notUpdated = $result['methodResponses'][0][1]['notUpdated'][$accountId] ?? null;
        if ($notUpdated !== null) {
            throw new RuntimeException('Stalwart password update failed: '.json_encode($notUpdated));
        }
    }

    public function deleteAccount(?string $accountId): void
    {
        if (! filled($accountId)) {
            return;
        }

        $this->call([['x:Account/set', [
            'destroy' => [$accountId],
        ], 'a3']]);
    }

    /**
     * Blocked IPs from Stalwart auto-ban / the manual block list.
     *
     * Tries the JMAP BlockedIp object API first (current Stalwart), then the
     * v0.x settings keys under `server.blocked-ip.*`.
     *
     * @return list<array{id: string, ip: string, reason: string, created: ?string}>
     */
    public function listBlockedIps(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        try {
            $entries = $this->listBlockedIpsViaJmap();
            if ($entries !== null) {
                return $entries;
            }
        } catch (Throwable) {
            // Fall through to the settings API used by Stalwart v0.x.
        }

        return $this->listBlockedIpsViaSettings();
    }

    /**
     * @return array{id: string, ip: string, reason: string, created: ?string}|null
     */
    public function findBlockedIp(string $ip): ?array
    {
        foreach ($this->listBlockedIps() as $entry) {
            if ($this->matchesBlockedAddress($entry['ip'], $ip)) {
                return $entry;
            }
        }

        return null;
    }

    public function matchesBlockedAddress(string $blocked, string $ip): bool
    {
        $blocked = trim($blocked);
        $ip = trim($ip);

        if ($blocked === '' || $ip === '') {
            return false;
        }

        if (strcasecmp($blocked, $ip) === 0) {
            return true;
        }

        try {
            return IpUtils::checkIp($ip, $blocked);
        } catch (Throwable) {
            return false;
        }
    }

    public function unblockIp(string $ip): void
    {
        $ip = trim($ip);

        if ($ip === '') {
            throw new RuntimeException('Missing IP address.');
        }

        $jmapOk = false;

        try {
            $ids = $this->jmapIdsForBlockedIp($ip);
            if ($ids !== []) {
                $this->jmapDestroyBlockedIps($ids);
                $jmapOk = true;
            }
        } catch (Throwable) {
            $jmapOk = false;
        }

        $settingsOk = false;

        try {
            $this->settingsDeleteBlockedIp($ip);
            $settingsOk = true;
        } catch (Throwable) {
            if (! $jmapOk) {
                throw new RuntimeException('Could not unblock IP on the mail server.');
            }
        }

        if (! $jmapOk && ! $settingsOk) {
            throw new RuntimeException('Could not unblock IP on the mail server.');
        }

        $this->reloadBlockedIps();
    }

    /**
     * @return list<array{id: string, ip: string, reason: string, created: ?string}>|null
     */
    protected function listBlockedIpsViaJmap(): ?array
    {
        $result = $this->call([['x:BlockedIp/get', ['ids' => null], 'b0']]);
        $row = $result['methodResponses'][0] ?? null;

        if (! is_array($row)) {
            return null;
        }

        if (($row[0] ?? '') === 'error') {
            $type = (string) ($row[1]['type'] ?? '');
            if ($type === 'unknownMethod') {
                return null;
            }

            throw new RuntimeException('Stalwart blocked-IP list failed.');
        }

        if (($row[0] ?? '') !== 'x:BlockedIp/get') {
            return null;
        }

        $list = $row[1]['list'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        $entries = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $entry = $this->normalizeBlockedEntry(
                (string) ($item['id'] ?? ''),
                (string) ($item['address'] ?? $item['ip'] ?? ''),
                (string) ($item['reason'] ?? ''),
                $item['createdAt'] ?? $item['created'] ?? null,
            );
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<array{id: string, ip: string, reason: string, created: ?string}>
     */
    protected function listBlockedIpsViaSettings(): array
    {
        $response = $this->managementGet(
            ['/api/settings/list', '/settings/list'],
            ['prefix' => 'server.blocked-ip'],
        );

        $map = $this->settingsMap($response->json());
        $entries = [];

        foreach ($map as $key => $value) {
            $ip = $this->ipFromSettingsKey((string) $key);
            if ($ip === null) {
                continue;
            }

            [$reason, $created] = $this->parseSettingsValue($value);
            $entry = $this->normalizeBlockedEntry($ip, $ip, $reason, $created);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return array_values($entries);
    }

    /**
     * @return list<string>
     */
    protected function jmapIdsForBlockedIp(string $ip): array
    {
        $entries = $this->listBlockedIpsViaJmap();
        if ($entries === null) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            if ($this->matchesBlockedAddress($entry['ip'], $ip)) {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $ids
     */
    protected function jmapDestroyBlockedIps(array $ids): void
    {
        $result = $this->call([['x:BlockedIp/set', ['destroy' => array_values($ids)], 'b1']]);
        $row = $result['methodResponses'][0] ?? null;
        if (is_array($row) && ($row[0] ?? '') === 'error') {
            throw new RuntimeException('Stalwart blocked-IP delete failed.');
        }
    }

    protected function settingsDeleteBlockedIp(string $ip): void
    {
        $keys = [
            'server.blocked-ip.'.$ip,
            'server.blocked-ip.'.strtolower($ip),
        ];

        $payloads = [
            [['type' => 'delete', 'keys' => $keys]],
            [['type' => 'Delete', 'keys' => $keys]],
        ];

        $last = null;
        foreach (['/api/settings', '/settings'] as $path) {
            foreach ($payloads as $payload) {
                $response = $this->http()->connectTimeout(3)->post($path, $payload);
                $last = $response;
                if ($response->successful()) {
                    return;
                }
            }
        }

        if ($last && in_array($last->status(), [404, 405], true)) {
            return;
        }

        throw new RuntimeException('Stalwart blocked-IP settings delete failed.');
    }

    protected function reloadBlockedIps(): void
    {
        try {
            $this->call([['x:Action/set', [
                'create' => [
                    'reload' => ['@type' => 'ReloadBlockedIps'],
                ],
            ], 'r0']]);
        } catch (Throwable) {
            // Optional on older servers.
        }

        foreach (['/api/reload', '/reload'] as $path) {
            try {
                $response = $this->http()->connectTimeout(3)->get($path);
                if ($response->successful()) {
                    return;
                }
            } catch (Throwable) {
                // Best-effort; the delete already persisted.
            }
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<string, mixed>  $query
     */
    protected function managementGet(array $paths, array $query = []): Response
    {
        $last = null;

        foreach ($paths as $path) {
            $response = $this->http()->connectTimeout(3)->get($path, $query);
            $last = $response;
            if ($response->successful()) {
                return $response;
            }
            if (! in_array($response->status(), [404, 405], true)) {
                break;
            }
        }

        throw new RuntimeException('Stalwart settings HTTP '.($last?->status() ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsMap(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $data = $json['data'] ?? $json;
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['items']) && is_array($data['items'])) {
            $data = $data['items'];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || in_array($key, ['total', 'items'], true)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    protected function ipFromSettingsKey(string $key): ?string
    {
        $key = trim($key);
        if (str_starts_with($key, 'server.blocked-ip.')) {
            $key = substr($key, strlen('server.blocked-ip.'));
        }

        $key = trim($key, '.');
        if ($key === '' || in_array($key, ['enable', 'enabled'], true)) {
            return null;
        }

        $address = $key;
        if (str_contains($key, '/')) {
            $address = Str::before($key, '/');
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $key;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    protected function parseSettingsValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['', null];
        }

        if (is_array($value)) {
            return [
                (string) ($value['reason'] ?? ''),
                $this->normalizeCreated($value['created'] ?? $value['createdAt'] ?? null),
            ];
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return ['', null];
        }

        if (str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->parseSettingsValue($decoded);
            }
        }

        return [$raw, null];
    }

    /**
     * @return array{id: string, ip: string, reason: string, created: ?string}|null
     */
    protected function normalizeBlockedEntry(string $id, string $ip, string $reason, mixed $created): ?array
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        $address = str_contains($ip, '/') ? Str::before($ip, '/') : $ip;
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return [
            'id' => $id !== '' ? $id : $ip,
            'ip' => $ip,
            'reason' => trim($reason),
            'created' => $this->normalizeCreated($created),
        ];
    }

    protected function normalizeCreated(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts > 10_000_000_000) {
                $ts = (int) floor($ts / 1000);
            }

            return gmdate('c', $ts);
        }

        $str = trim((string) $value);

        return $str !== '' ? $str : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitEmail(string $email): array
    {
        $email = Str::lower(trim($email));
        if (! str_contains($email, '@')) {
            throw new RuntimeException('Invalid mailbox email.');
        }

        return [Str::before($email, '@'), Str::after($email, '@')];
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('maildesk.stalwart.url'))
            ->withBasicAuth(
                (string) config('maildesk.stalwart.admin_user'),
                (string) config('maildesk.stalwart.admin_password'),
            )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout((int) config('maildesk.stalwart.timeout', 15));
    }
}
