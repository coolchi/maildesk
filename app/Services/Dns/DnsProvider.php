<?php

namespace App\Services\Dns;

/**
 * A DNS host MailDesk can manage records on. Records are plain arrays:
 * {id, type, name (fully qualified), content, priority?}.
 */
interface DnsProvider
{
    /**
     * Find the zone that holds $domain.
     *
     * @return array{id: string, name: string}
     */
    public function findZone(string $domain): array;

    /**
     * @return list<array{id: string, type: string, name: string, content: string, priority: ?int}>
     */
    public function records(string $zoneId): array;

    /**
     * @param  array{type: string, name: string, content: string, priority?: ?int}  $record
     * @return array<string, mixed>
     */
    public function create(string $zoneId, array $record): array;

    /**
     * @param  array{content: string, priority?: ?int}  $changes
     * @return array<string, mixed>
     */
    public function update(string $zoneId, string $recordId, array $changes): array;

    public function delete(string $zoneId, string $recordId): void;
}
