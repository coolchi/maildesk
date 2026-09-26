<?php

namespace App\Services\Domains;

/**
 * Thin, read-only wrapper around dns_get_record() for host verification.
 * Tests bind a fake subclass in the container so nothing hits the network.
 */
class HostRecordResolver
{
    /**
     * @return list<string> IPv4 addresses.
     */
    public function a(string $host): array
    {
        return $this->values($host, DNS_A, 'ip');
    }

    /**
     * @return list<string> CNAME targets, lower-case, no trailing dot.
     */
    public function cname(string $host): array
    {
        return array_values(array_map(
            fn (string $target) => rtrim(strtolower($target), '.'),
            $this->values($host, DNS_CNAME, 'target'),
        ));
    }

    /**
     * @return list<string> TXT records with their character-strings joined.
     */
    public function txt(string $host): array
    {
        $records = $this->query($host, DNS_TXT);

        return array_values(array_map(
            fn (array $record) => isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : (string) ($record['txt'] ?? ''),
            $records,
        ));
    }

    /**
     * @return list<string>
     */
    protected function values(string $host, int $type, string $field): array
    {
        return array_values(array_filter(array_map(
            fn (array $record) => (string) ($record[$field] ?? ''),
            $this->query($host, $type),
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function query(string $host, int $type): array
    {
        $host = rtrim(trim($host), '.');

        if ($host === '') {
            return [];
        }

        $records = @dns_get_record($host, $type);

        return is_array($records) ? array_values($records) : [];
    }
}
