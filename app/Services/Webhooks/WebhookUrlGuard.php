<?php

namespace App\Services\Webhooks;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF protection for outgoing webhooks.
 *
 * check() runs the static rules (scheme, host name, literal IPs) and is used
 * when an endpoint is saved. resolve() runs them again at delivery time,
 * resolves DNS and rejects the endpoint if ANY resolved address is internal,
 * returning one vetted address so the HTTP client can be pinned to it
 * (no second lookup, so DNS rebinding can't swap the target).
 */
class WebhookUrlGuard
{
    /** Loopback, private, link-local, CGNAT, metadata, reserved, multicast, docs. */
    public const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    public function __construct(private readonly WebhookHostResolver $resolver) {}

    public function requiresHttps(): bool
    {
        return ! app()->environment(['local', 'testing']);
    }

    /**
     * Static validation (no DNS). Returns an error message, or null when OK.
     */
    public function check(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Enter a valid endpoint URL.';
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook endpoints must use https://.';
        }

        if ($scheme !== 'https' && $this->requiresHttps()) {
            return 'Webhook endpoints must use https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Webhook URLs must not contain credentials.';
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        if ($host === '') {
            return 'Enter a valid endpoint URL.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host) ? 'Webhook endpoints cannot point to private or internal addresses.' : null;
        }

        // Shorthand/decimal/hex IPv4 forms (127.1, 2130706433, 0x7f.0.0.1).
        if (preg_match('/^[0-9.]+$/', $host) || preg_match('/^0x[0-9a-f]+/i', $host)) {
            return 'Webhook endpoints cannot point to private or internal addresses.';
        }

        if ($this->isBlockedHostname($host)) {
            return 'Webhook endpoints cannot point to private or internal addresses.';
        }

        return null;
    }

    /**
     * Delivery-time validation with DNS resolution.
     *
     * @return array{host: string, port: int, ip: string}
     *
     * @throws BlockedWebhookUrlException
     */
    public function resolve(string $url): array
    {
        if (($error = $this->check($url)) !== null) {
            throw new BlockedWebhookUrlException($error);
        }

        $parts = parse_url(trim($url));
        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new BlockedWebhookUrlException("Could not resolve {$host}.");
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new BlockedWebhookUrlException("{$host} resolves to a private or internal address.");
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public function isBlockedIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return IpUtils::checkIp($ip, self::BLOCKED_RANGES);
    }

    public function isBlockedHostname(string $host): bool
    {
        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.localdomain', '.home.arpa', '.arpa'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
