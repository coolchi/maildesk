<?php

namespace App\Services\Dns;

use App\Models\DnsConnection;
use App\Models\Domain;
use App\Services\Domains\DomainVerifier;
use Illuminate\Support\Str;

/**
 * Compares the records a domain needs with what is live at its DNS host, and
 * applies or edits them. Only mail records are touched: MX/TXT/CNAME at the
 * hostnames MailDesk asked for, so a client can't break their website's DNS here.
 */
class DnsRecordManager
{
    public const EDITABLE_TYPES = ['MX', 'TXT', 'CNAME'];

    public function __construct(private readonly DomainVerifier $verifier) {}

    public function provider(DnsConnection $connection): DnsProvider
    {
        return match ($connection->provider) {
            'cloudflare' => new CloudflareDnsProvider((string) ($connection->credentials['api_token'] ?? '')),
            default => throw new DnsProviderException("Unsupported DNS provider: {$connection->provider}"),
        };
    }

    /**
     * The records the domain needs, with fully qualified hostnames.
     *
     * @return list<array<string, mixed>>
     */
    public function desired(Domain $domain): array
    {
        return array_values(array_map(function (array $row) use ($domain) {
            $hosts = $row['hosts'] ?? $this->verifier->candidateHosts((string) ($row['name'] ?? ''), $domain->name);

            return [
                'key' => (string) ($row['key'] ?? ''),
                'type' => strtoupper((string) ($row['type'] ?? 'TXT')),
                'host' => strtolower((string) end($hosts)),
                'value' => (string) ($row['value'] ?? ''),
                'priority' => $row['priority'] ?? null,
            ];
        }, $domain->normalizedDnsRecords()['records']));
    }

    /**
     * @return array{records: list<array<string, mixed>>, live: list<array<string, mixed>>}
     */
    public function plan(Domain $domain, DnsConnection $connection): array
    {
        $desired = $this->desired($domain);
        $hosts = array_unique(array_column($desired, 'host'));
        $live = array_values(array_filter(
            $this->provider($connection)->records($connection->zone_id),
            fn (array $r) => in_array($r['name'], $hosts, true) && in_array($r['type'], self::EDITABLE_TYPES, true),
        ));

        $records = array_map(fn (array $want) => $want + $this->compare($want, $live), $desired);

        return ['records' => $records, 'live' => $live];
    }

    /**
     * Create what's missing and fix what's different. Returns counts.
     *
     * @return array{created: int, updated: int}
     */
    public function apply(Domain $domain, DnsConnection $connection, ?string $key = null): array
    {
        $provider = $this->provider($connection);
        $counts = ['created' => 0, 'updated' => 0];

        foreach ($this->plan($domain, $connection)['records'] as $row) {
            if ($key !== null && $row['key'] !== $key) {
                continue;
            }

            if ($row['state'] === 'missing') {
                $provider->create($connection->zone_id, [
                    'type' => $row['type'],
                    'name' => $row['host'],
                    'content' => $row['value'],
                    'priority' => $row['priority'],
                ]);
                $counts['created']++;
            } elseif ($row['state'] === 'different') {
                $content = $row['key'] === 'spf'
                    ? $this->mergeSpf((string) $row['live_value'], $row['value'])
                    : $row['value'];
                $provider->update($connection->zone_id, (string) $row['live_id'], [
                    'content' => $content,
                    'priority' => $row['type'] === 'MX' ? $row['priority'] : null,
                ]);
                $counts['updated']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array{content: string, priority?: ?int}  $changes
     */
    public function updateRecord(Domain $domain, DnsConnection $connection, string $recordId, array $changes): void
    {
        $record = $this->managedRecord($domain, $connection, $recordId);

        $this->provider($connection)->update($connection->zone_id, $recordId, [
            'content' => trim($changes['content']),
            'priority' => $record['type'] === 'MX' ? ($changes['priority'] ?? $record['priority']) : null,
        ]);
    }

    public function deleteRecord(Domain $domain, DnsConnection $connection, string $recordId): void
    {
        $this->managedRecord($domain, $connection, $recordId);
        $this->provider($connection)->delete($connection->zone_id, $recordId);
    }

    /**
     * Add the includes from $expected to an existing SPF record instead of
     * creating a second one (two SPF records at one name make both invalid).
     */
    public function mergeSpf(string $current, string $expected): string
    {
        preg_match_all('/\binclude:\S+/i', $expected, $wanted);
        $tokens = preg_split('/\s+/', trim($current)) ?: [];
        $missing = array_values(array_filter($wanted[0], fn ($inc) => ! in_array(strtolower($inc), array_map('strtolower', $tokens), true)));

        if ($missing === []) {
            return trim($current);
        }

        $allIndex = null;
        foreach ($tokens as $i => $token) {
            if (preg_match('/^[~?+-]?all$/i', $token) || str_starts_with(strtolower($token), 'redirect=')) {
                $allIndex = $i;
                break;
            }
        }

        $allIndex ??= count($tokens);
        array_splice($tokens, $allIndex, 0, $missing);

        return implode(' ', $tokens);
    }

    /**
     * @return array<string, mixed>
     */
    private function managedRecord(Domain $domain, DnsConnection $connection, string $recordId): array
    {
        $record = collect($this->plan($domain, $connection)['live'])->firstWhere('id', $recordId);

        if ($record === null) {
            throw new DnsProviderException('That record is not one of the mail records MailDesk manages for this domain.');
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $want
     * @param  list<array<string, mixed>>  $live
     * @return array{state: string, live_id: ?string, live_value: ?string}
     */
    private function compare(array $want, array $live): array
    {
        $same = array_values(array_filter($live, fn ($r) => $r['name'] === $want['host'] && $r['type'] === $want['type']));
        $result = fn (string $state, ?array $r = null) => [
            'state' => $state,
            'live_id' => $r['id'] ?? null,
            'live_value' => $r['content'] ?? null,
        ];

        if ($want['type'] === 'MX') {
            foreach ($same as $r) {
                if (rtrim(strtolower($r['content']), '.') === rtrim(strtolower($want['value']), '.')) {
                    return $result('ok', $r);
                }
            }

            // Other MX hosts at this name are left alone; ours is added alongside.
            return $result('missing');
        }

        if ($want['type'] === 'TXT') {
            $prefix = match ($want['key']) {
                'spf' => 'v=spf1',
                'dmarc' => 'v=dmarc1',
                'dkim' => null,
                default => null,
            };

            $candidates = $prefix === null
                ? $same
                : array_values(array_filter($same, fn ($r) => Str::startsWith(strtolower(trim($r['content'])), $prefix)));

            if ($candidates === []) {
                return $result('missing');
            }

            $r = $candidates[0];
            $ok = match ($want['key']) {
                'spf' => $this->mergeSpf($r['content'], $want['value']) === trim($r['content']),
                'dmarc' => true, // any valid DMARC policy counts; the client owns it
                default => preg_replace('/\s+/', '', $r['content']) === preg_replace('/\s+/', '', $want['value']),
            };

            return $result($ok ? 'ok' : 'different', $r);
        }

        $r = $same[0] ?? null;

        if ($r === null) {
            return $result('missing');
        }

        return $result(rtrim(strtolower($r['content']), '.') === rtrim(strtolower($want['value']), '.') ? 'ok' : 'different', $r);
    }
}
