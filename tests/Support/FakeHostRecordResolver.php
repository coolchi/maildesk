<?php

namespace Tests\Support;

use App\Services\Domains\HostRecordResolver;

/**
 * In-memory DNS for host verification tests: no network.
 */
class FakeHostRecordResolver extends HostRecordResolver
{
    /** @var array<string, array<int, array<int, string>>> */
    public array $records = [];

    public array $queried = [];

    public function set(string $host, int $type, array $values): static
    {
        $this->records[strtolower($host)][$type] = $values;

        return $this;
    }

    protected function query(string $host, int $type): array
    {
        $this->queried[] = [$host, $type];
        $values = $this->records[strtolower(rtrim($host, '.'))][$type] ?? [];

        return array_map(fn (string $value) => match ($type) {
            DNS_A => ['ip' => $value],
            DNS_CNAME => ['target' => $value],
            default => ['txt' => $value, 'entries' => [$value]],
        }, $values);
    }
}
