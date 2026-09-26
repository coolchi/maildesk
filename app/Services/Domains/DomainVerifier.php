<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Services\Dns\DnsProviderException;
use App\Services\Dns\DnsRecordManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Verifies a sending domain against real DNS and (for Resend domains)
 * registers it with Resend so the published records are the real ones.
 */
class DomainVerifier
{
    /** Checks that must pass for a domain to count as verified. */
    public const REQUIRED = ['spf', 'dkim'];

    /** Checks that are recommended: failing them adds a warning but does not block verification. */
    public const RECOMMENDED = ['dmarc'];

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly ResendDomainClient $resend,
    ) {}

    /**
     * Run a full verification pass and persist the result.
     *
     * @return array{verified: bool, checks: array<string, bool>, failing: list<string>, warnings: list<string>, provider_error: ?string}
     */
    public function verify(Domain $domain): array
    {
        $dns = $domain->normalizedDnsRecords();
        $providerError = null;

        if ($this->shouldSyncWithResend($domain)) {
            try {
                $dns = $this->syncWithResend($domain, $dns);
            } catch (RuntimeException $e) {
                $providerError = $e->getMessage();
                Log::warning('Resend domain sync failed', [
                    'domain' => $domain->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $dns = $this->autoPublish($domain, $dns);

        [$checks, $results, $required] = $this->checkRecords($domain->name, $dns['records']);

        $failing = array_values(array_filter($required, fn (string $key) => ! ($checks[$key] ?? false)));
        $verified = $failing === [];
        $warnings = $this->warnings($domain->name, $checks);

        $dns['checks'] = $checks;
        $dns['results'] = $results;
        $dns['required'] = $required;
        $dns['warnings'] = $warnings;
        $dns['checked_at'] = now()->toIso8601String();
        $dns['provider_error'] = $providerError;

        $domain->forceFill([
            'status' => $verified ? 'verified' : 'failed',
            'verified_at' => $verified ? ($domain->verified_at ?? now()) : null,
            'dns_records' => $dns,
        ])->save();

        return [
            'verified' => $verified,
            'checks' => $checks,
            'failing' => $failing,
            'warnings' => $warnings,
            'provider_error' => $providerError,
        ];
    }

    /**
     * @param  array<string, bool>  $checks
     * @return list<string>
     */
    private function warnings(string $domain, array $checks): array
    {
        $warnings = [];

        if (! ($checks['dmarc'] ?? false)) {
            $warnings[] = "DMARC record missing or invalid at _dmarc.{$domain}. Mail will still send, but adding one (e.g. \"v=DMARC1; p=none;\") improves deliverability.";
        }

        return $warnings;
    }

    /**
     * When the client has connected their DNS host, publish whatever the
     * provider asks for (and fix drifted values) before checking, so nobody
     * has to copy records by hand. Only mail records at MailDesk's hosts are
     * touched. A failure here is recorded, never fatal to verification.
     *
     * @param  array<string, mixed>  $dns
     * @return array<string, mixed>
     */
    private function autoPublish(Domain $domain, array $dns): array
    {
        $connection = $domain->dnsConnection;

        if ($connection === null || ! config('maildesk.domains.auto_publish_dns', true)) {
            return $dns;
        }

        // The record manager reads the domain's records; hand it the fresh set.
        $domain->setAttribute('dns_records', $dns);

        try {
            // Resolved lazily: the record manager itself depends on this class.
            $counts = app(DnsRecordManager::class)->apply($domain, $connection);
            $dns['auto_publish'] = [...$counts, 'error' => null, 'at' => now()->toIso8601String()];

            if ($counts['created'] + $counts['updated'] > 0 && filled($domain->provider_domain_id) && $this->shouldSyncWithResend($domain)) {
                // Ask the provider to look again now that the records exist.
                $this->resend->triggerVerify((string) $domain->provider_domain_id);
            }
        } catch (DnsProviderException $e) {
            Log::warning('Automatic DNS publish failed', ['domain' => $domain->name, 'error' => $e->getMessage()]);
            $dns['auto_publish'] = ['created' => 0, 'updated' => 0, 'error' => $e->getMessage(), 'at' => now()->toIso8601String()];
        }

        return $dns;
    }

    private function shouldSyncWithResend(Domain $domain): bool
    {
        return $domain->provider === 'resend'
            && (bool) config('maildesk.domains.register_with_provider', true)
            && $this->resend->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $dns
     * @return array<string, mixed>
     */
    private function syncWithResend(Domain $domain, array $dns): array
    {
        if (blank($domain->provider_domain_id)) {
            $remote = $this->resend->register($domain->name, $domain->organization?->region);
            $domain->forceFill(['provider_domain_id' => (string) $remote['id']])->save();
        }

        $remote = $this->resend->get((string) $domain->provider_domain_id);

        if ($remote === null) {
            // The stored id no longer exists on this Resend account (deleted or
            // re-created elsewhere). Adopt the live domain with the same name.
            $existing = $this->resend->findByName($domain->name);

            if ($existing !== null) {
                $domain->forceFill(['provider_domain_id' => (string) $existing['id']])->save();
                $remote = $this->resend->get((string) $existing['id']) ?? $existing;
            }
        }

        $this->resend->triggerVerify((string) $domain->provider_domain_id);
        $remote ??= [];

        $dns['provider'] = [
            'name' => 'resend',
            'id' => $domain->provider_domain_id,
            'status' => $remote['status'] ?? null,
            'region' => $remote['region'] ?? null,
        ];

        $rows = $this->rowsFromResend((array) ($remote['records'] ?? []), $domain->name);

        if ($rows !== []) {
            // Resend does not issue a DMARC record; keep ours.
            $dmarc = collect($dns['records'])->firstWhere('key', 'dmarc')
                ?? collect(Domain::defaultDnsRecords($domain->name)['records'])->firstWhere('key', 'dmarc');
            // The DMARC host always belongs to this domain, whatever was stored before.
            $dmarc['name'] = "_dmarc.{$domain->name}";
            unset($dmarc['hosts']);
            $dns['records'] = [...$rows, $dmarc];
        }

        return $dns;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function rowsFromResend(array $records, string $domain): array
    {
        $rows = [];

        foreach ($records as $record) {
            $kind = strtoupper((string) ($record['record'] ?? ''));
            $type = strtoupper((string) ($record['type'] ?? ''));

            $key = match (true) {
                $kind === 'DKIM' => 'dkim',
                $kind === 'SPF' && $type === 'TXT' => 'spf',
                $kind === 'SPF' && $type === 'MX' => 'mx',
                $kind === 'SPF' && $type === 'CNAME' => 'return_path',
                str_starts_with($kind, 'RECEIV') => 'inbound_mx',
                // Anything new the provider adds is still listed and published.
                in_array($type, DnsRecordManager::EDITABLE_TYPES, true) && filled($record['name'] ?? null) => strtolower($type).'_'.trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $record['name'])), '_'),
                default => null,
            };

            if ($key === null) {
                continue;
            }

            $rows[] = array_filter([
                'type' => $type,
                'name' => (string) ($record['name'] ?? ''),
                'value' => trim((string) ($record['value'] ?? ''), '"'),
                'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
                'label' => match ($key) {
                    'dkim' => 'DKIM',
                    'spf' => 'SPF',
                    'mx' => 'MX (bounce / return-path)',
                    'inbound_mx' => 'MX (receiving)',
                    'return_path' => 'CNAME (return path)',
                    default => "{$type} record",
                },
                'key' => $key,
                'hosts' => $this->candidateHosts((string) ($record['name'] ?? ''), $domain),
            ], fn ($v) => $v !== null);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: array<string, bool>, 1: array<string, array<string, mixed>>, 2: list<string>}
     */
    private function checkRecords(string $domain, array $rows): array
    {
        $checks = [];
        $results = [];

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? Str::lower((string) ($row['label'] ?? $row['type'] ?? 'record')));
            $hosts = $row['hosts'] ?? $this->candidateHosts((string) ($row['name'] ?? ''), $domain);
            [$pass, $found] = $this->checkRow($row, $hosts);

            $checks[$key] = ($checks[$key] ?? true) && $pass;
            $results[$key] = [
                'pass' => $checks[$key],
                'hosts' => $hosts,
                'found' => array_values(array_unique([...($results[$key]['found'] ?? []), ...$found])),
            ];
        }

        foreach ([...self::REQUIRED, ...self::RECOMMENDED] as $key) {
            $checks[$key] ??= false;
        }

        $required = self::REQUIRED;

        // Every other record the provider issued is needed to send, except
        // receiving MX (inbound only) and DMARC (recommended).
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');

            if ($key !== '' && ! in_array($key, [...$required, 'mx', 'inbound_mx', ...self::RECOMMENDED], true)) {
                $required[] = $key;
            }
        }

        if (array_key_exists('mx', $checks)) {
            // Resend issued a return-path MX; sending needs it.
            $required[] = 'mx';
        } else {
            // Informational: does the domain receive mail at all?
            $mx = $this->dns->mx($domain);
            $checks['mx'] = $mx !== [];
            $results['mx'] = [
                'pass' => $checks['mx'],
                'hosts' => [$domain],
                'found' => array_map(fn ($r) => "{$r['priority']} {$r['host']}", $mx),
            ];
        }

        return [$checks, $results, $required];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $hosts
     * @return array{0: bool, 1: list<string>}
     */
    private function checkRow(array $row, array $hosts): array
    {
        $type = strtoupper((string) ($row['type'] ?? 'TXT'));
        $key = (string) ($row['key'] ?? '');
        $expected = (string) ($row['value'] ?? '');
        $found = [];
        $pass = false;

        foreach ($hosts as $host) {
            if ($type === 'CNAME') {
                foreach ($this->dns->cname($host) as $target) {
                    $found[] = $target;
                    $pass = $pass || $target === rtrim(strtolower($expected), '.');
                }

                continue;
            }

            if ($type === 'MX') {
                foreach ($this->dns->mx($host) as $mx) {
                    $found[] = "{$mx['priority']} {$mx['host']}";
                    $pass = $pass || $mx['host'] === rtrim(strtolower($expected), '.');
                }

                continue;
            }

            foreach ($this->dns->txt($host) as $txt) {
                $found[] = Str::limit($txt, 255);
                $pass = $pass || match ($key) {
                    'spf' => $this->spfMatches($txt, $expected),
                    'dmarc' => (bool) preg_match('/^v=DMARC1\b/i', trim($txt)),
                    'dkim' => $this->dkimMatches($txt, $expected),
                    default => $this->normalize($txt) === $this->normalize($expected),
                };
            }
        }

        return [$pass, $found];
    }

    private function spfMatches(string $txt, string $expected): bool
    {
        if (! preg_match('/^v=spf1\b/i', trim($txt))) {
            return false;
        }

        preg_match_all('/include:(\S+)/i', $expected, $includes);

        foreach ($includes[1] as $include) {
            if (stripos($txt, 'include:'.$include) === false) {
                return false;
            }
        }

        return true;
    }

    private function dkimMatches(string $txt, string $expected): bool
    {
        // Placeholder values (never registered with a provider): accept any public key.
        if ($expected === '' || str_contains($expected, 'MockDkimKey') || str_ends_with($expected, '…')) {
            return (bool) preg_match('/(^|;)\s*p=[A-Za-z0-9+\/=]+/', $txt);
        }

        return $this->normalize($txt) === $this->normalize($expected);
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[\s"]+/', '', $value));
    }

    /**
     * Resend returns record names relative to the zone apex (for in.desk.ng
     * the DKIM name is "resend._domainkey.in"), but we cannot know the apex,
     * so try both "<name>.<domain>" and the overlap-stripped form.
     *
     * @return list<string>
     */
    public function candidateHosts(string $name, string $domain): array
    {
        $name = rtrim(strtolower(trim($name)), '.');
        $domain = strtolower($domain);

        if ($name === '' || $name === '@' || $name === $domain) {
            return [$domain];
        }

        if (str_ends_with($name, '.'.$domain)) {
            return [$name];
        }

        $hosts = ["{$name}.{$domain}"];
        $nameLabels = explode('.', $name);
        $domainLabels = explode('.', $domain);

        // Resend names records relative to the registered zone, so for in.desk.ng
        // "send.in" means send.in.desk.ng and a bare "in" means in.desk.ng itself.
        for ($k = min(count($nameLabels), count($domainLabels) - 1); $k >= 1; $k--) {
            if (array_slice($nameLabels, -$k) === array_slice($domainLabels, 0, $k)) {
                $hosts[] = implode('.', [...array_slice($nameLabels, 0, -$k), ...$domainLabels]);
                break;
            }
        }

        return array_values(array_unique($hosts));
    }
}
