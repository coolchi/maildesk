<?php

namespace App\Services\Domains;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DNS lookups for domain verification.
 *
 * By default this asks a public resolver (Cloudflare 1.1.1.1, via its
 * DNS-over-HTTPS JSON API) rather than the machine's own resolver. Home and
 * office routers cache "doesn't exist" answers for up to the zone's SOA
 * minimum (often 30 minutes), which made freshly added records show as
 * "Not found". If the public resolver can't be reached, it falls back to
 * PHP's system resolver.
 *
 * Tests bind a subclass in the container to return fake answers.
 */
class DnsResolver
{
    private const TYPE_MX = 15;

    private const TYPE_TXT = 16;

    private const TYPE_CNAME = 5;

    /**
     * @return list<string> Each TXT record with its character-strings joined.
     */
    public function txt(string $host): array
    {
        $answers = $this->queryPublic($host, 'TXT', self::TYPE_TXT);

        if ($answers !== null) {
            return array_values(array_map(fn (string $data) => $this->joinTxt($data), $answers));
        }

        return $this->systemTxt($host);
    }

    /**
     * @return list<array{host: string, priority: int}>
     */
    public function mx(string $host): array
    {
        $answers = $this->queryPublic($host, 'MX', self::TYPE_MX);

        if ($answers !== null) {
            $rows = [];

            foreach ($answers as $data) {
                if (preg_match('/^\s*(\d+)\s+(\S+)\s*$/', $data, $m)) {
                    $rows[] = [
                        'host' => rtrim(strtolower($m[2]), '.'),
                        'priority' => (int) $m[1],
                    ];
                }
            }

            return $rows;
        }

        return $this->systemMx($host);
    }

    /**
     * @return list<string> CNAME targets, lower-case, without the trailing dot.
     */
    public function cname(string $host): array
    {
        $answers = $this->queryPublic($host, 'CNAME', self::TYPE_CNAME);

        if ($answers === null) {
            $records = @dns_get_record($host, DNS_CNAME);
            $answers = is_array($records) ? array_map(fn (array $r) => (string) ($r['target'] ?? ''), $records) : [];
        }

        return array_values(array_filter(array_map(fn (string $t) => rtrim(strtolower(trim($t)), '.'), $answers)));
    }

    /**
     * Ask the public resolver. Returns the record data strings (an empty list
     * when the name has no such records), or null when the resolver couldn't
     * give a usable answer and the caller should fall back.
     *
     * @return list<string>|null
     */
    protected function queryPublic(string $host, string $type, int $typeCode): ?array
    {
        if (config('maildesk.domains.resolver', 'doh') !== 'doh') {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get((string) config('maildesk.domains.doh_url', 'https://1.1.1.1/dns-query'), [
                    'name' => rtrim($host, '.'),
                    'type' => $type,
                ]);
        } catch (Throwable $e) {
            Log::warning('Public DNS lookup failed; using system resolver', ['host' => $host, 'type' => $type, 'error' => $e->getMessage()]);

            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        // Status 0 = NOERROR, 3 = NXDOMAIN (name doesn't exist: no records).
        // Anything else (e.g. 2 = SERVFAIL) is inconclusive, so fall back.
        if (! is_array($json) || ! in_array($json['Status'] ?? null, [0, 3], true)) {
            Log::warning('Public DNS lookup inconclusive; using system resolver', ['host' => $host, 'type' => $type, 'status' => $response->status()]);

            return null;
        }

        $data = [];

        foreach ((array) ($json['Answer'] ?? []) as $answer) {
            // Skip CNAME hops; keep only the record type we asked for.
            if ((int) ($answer['type'] ?? 0) === $typeCode) {
                $data[] = (string) ($answer['data'] ?? '');
            }
        }

        return $data;
    }

    /**
     * DoH returns TXT data as one or more quoted strings: "v=spf1 ..." "...".
     * Cloudflare escapes each non-ASCII byte as \DDD (decimal), not C octal.
     */
    private function joinTxt(string $data): string
    {
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $m) && $m[1] !== []) {
            return implode('', array_map($this->unescapeTxt(...), $m[1]));
        }

        return trim($data, '"');
    }

    private function unescapeTxt(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $value[$i];

                continue;
            }

            $next = $value[$i + 1];

            if (ctype_digit($next)) {
                $digits = '';

                while ($i + 1 < $length && ctype_digit($value[$i + 1]) && strlen($digits) < 3) {
                    $digits .= $value[++$i];
                }

                $byte = (int) $digits;
                $out .= $byte <= 255 ? chr($byte) : '\\'.$digits;

                continue;
            }

            $out .= stripcslashes('\\'.$next);
            $i++;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function systemTxt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_map(
            fn (array $record) => isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : (string) ($record['txt'] ?? ''),
            $records,
        ));
    }

    /**
     * @return list<array{host: string, priority: int}>
     */
    private function systemMx(string $host): array
    {
        $records = @dns_get_record($host, DNS_MX);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_map(
            fn (array $record) => [
                'host' => rtrim(strtolower((string) ($record['target'] ?? '')), '.'),
                'priority' => (int) ($record['pri'] ?? 0),
            ],
            $records,
        ));
    }
}
