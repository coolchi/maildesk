<?php

namespace App\Services\Webhooks;

/**
 * Resolves a webhook host to its A/AAAA addresses. Bound in the container
 * so tests can swap in a fake instead of doing live DNS.
 */
class WebhookHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
