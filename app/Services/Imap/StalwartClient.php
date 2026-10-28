<?php

namespace App\Services\Imap;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

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
            ->timeout((int) config('maildesk.stalwart.timeout', 15));
    }
}
