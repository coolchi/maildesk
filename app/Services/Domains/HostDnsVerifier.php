<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Models\OrganizationHost;
use App\Services\TenantResolver;
use Illuminate\Support\Str;

/**
 * Read-only DNS verification for admin hosts (Admin → Subdomains → Verify).
 *
 * Required checks:
 *  - routing: a custom host must CNAME to the platform (the base domain or a
 *    subdomain of it, or config('maildesk.custom_host_cname_target')), or its
 *    A records must match the platform's (config('maildesk.custom_host_ips')
 *    or, when unset, the base domain's own A records).
 *  - SPF + DKIM for each of the account's *verified* sending domains that has
 *    those records, re-checked against the expected values.
 * Advisory (never blocks): DMARC; sending domains not yet verified.
 *
 * Platform subdomains ({sub}.{base}) are served by the platform wildcard and
 * stay auto-active (no routing lookup needed).
 *
 * Never publishes or changes DNS and never calls the mail provider.
 */
class HostDnsVerifier
{
    public function __construct(
        protected HostRecordResolver $dns,
        protected TenantResolver $tenants,
    ) {}

    /**
     * @return array{passed: bool, checks: list<array{key: string, label: string, pass: bool, required: bool, detail: string}>, failed: list<string>}
     */
    public function check(OrganizationHost $host): array
    {
        $checks = [];

        if ($host->is_custom) {
            $checks[] = $this->routingCheck(Str::lower($host->host));
        } else {
            $checks[] = [
                'key' => 'routing',
                'label' => 'Platform subdomain',
                'pass' => true,
                'required' => true,
                'detail' => 'Served by the platform wildcard; no DNS lookup needed.',
            ];
        }

        $domains = Domain::query()->where('organization_id', $host->organization_id)->orderBy('name')->get();

        foreach ($domains as $domain) {
            array_push($checks, ...$this->sendingDomainChecks($domain));
        }

        $failed = array_values(array_map(
            fn (array $c) => $c['label'],
            array_filter($checks, fn (array $c) => $c['required'] && ! $c['pass']),
        ));

        return ['passed' => $failed === [], 'checks' => $checks, 'failed' => $failed];
    }

    /**
     * Run the checks and persist the outcome. Activates only when all
     * required checks pass; otherwise the host keeps (or returns to)
     * pending_dns with the failing checks recorded.
     *
     * @return array{passed: bool, checks: list<array<string, mixed>>, failed: list<string>}
     */
    public function verify(OrganizationHost $host): array
    {
        $result = $this->check($host);

        $host->forceFill([
            'status' => $result['passed'] ? 'active' : ($host->is_custom ? 'pending_dns' : $host->status),
            'ssl' => $result['passed'] ? true : $host->ssl,
            'dns_check' => $result,
            'dns_checked_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * @return array{key: string, label: string, pass: bool, required: bool, detail: string}
     */
    protected function routingCheck(string $hostname): array
    {
        $base = $this->tenants->baseDomain();
        $target = Str::lower((string) (config('maildesk.custom_host_cname_target') ?: $base));

        $cnames = $this->dns->cname($hostname);
        foreach ($cnames as $cname) {
            if ($cname === $target || $cname === $base || str_ends_with($cname, '.'.$base)) {
                return $this->row('routing', "{$hostname} points to the platform", true, true, "CNAME → {$cname}");
            }
        }

        $platformIps = array_values(array_filter((array) config('maildesk.custom_host_ips', [])));
        if ($platformIps === []) {
            $platformIps = $this->dns->a($target);
        }

        $ips = $this->dns->a($hostname);
        $match = array_values(array_intersect($ips, $platformIps));

        if ($match !== []) {
            return $this->row('routing', "{$hostname} points to the platform", true, true, 'A → '.implode(', ', $match));
        }

        $found = $cnames !== [] ? 'CNAME → '.implode(', ', $cnames) : ($ips !== [] ? 'A → '.implode(', ', $ips) : 'no CNAME or A record');

        return $this->row('routing', "{$hostname} points to the platform", false, true, "Expected a CNAME to {$target}; found {$found}.");
    }

    /**
     * @return list<array{key: string, label: string, pass: bool, required: bool, detail: string}>
     */
    protected function sendingDomainChecks(Domain $domain): array
    {
        $name = Str::lower($domain->name);
        $verified = $domain->status === 'verified';
        $rows = collect($domain->normalizedDnsRecords()['records'] ?? [])
            ->filter(fn ($row) => is_array($row) && in_array(($row['key'] ?? ''), ['spf', 'dkim', 'dmarc'], true));

        $checks = [];

        foreach (['spf', 'dkim'] as $key) {
            $keyRows = $rows->where('key', $key);

            if ($keyRows->isEmpty()) {
                continue;
            }

            $pass = $keyRows->every(fn (array $row) => $this->recordPresent($name, $row));
            $label = strtoupper($key)." for {$name}";

            $checks[] = $verified
                ? $this->row("{$key}:{$name}", $label, $pass, true, $pass ? 'Record found.' : 'Record missing or does not match the expected value.')
                : $this->row("{$key}:{$name}", $label, $pass, false, 'Domain not verified yet; informational only.');
        }

        $dmarc = collect($this->dns->txt("_dmarc.{$name}"))->contains(fn (string $txt) => (bool) preg_match('/^v=DMARC1\b/i', trim($txt)));
        $checks[] = $this->row("dmarc:{$name}", "DMARC for {$name}", $dmarc, false, $dmarc ? 'Record found.' : 'Recommended: add v=DMARC1 at _dmarc.'.$name.'.');

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function recordPresent(string $domain, array $row): bool
    {
        $key = (string) $row['key'];
        $expected = (string) ($row['value'] ?? '');
        $type = strtoupper((string) ($row['type'] ?? 'TXT'));
        $hosts = app(DomainVerifier::class)->candidateHosts((string) ($row['name'] ?? ''), $domain);

        foreach ($hosts as $host) {
            if ($type === 'CNAME') {
                if (in_array(rtrim(Str::lower($expected), '.'), $this->dns->cname($host), true)) {
                    return true;
                }

                continue;
            }

            foreach ($this->dns->txt($host) as $txt) {
                if ($key === 'spf' && $this->spfMatches($txt, $expected)) {
                    return true;
                }

                if ($key === 'dkim' && $this->normalize($txt) === $this->normalize($expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function spfMatches(string $txt, string $expected): bool
    {
        if (! preg_match('/^v=spf1\b/i', trim($txt))) {
            return false;
        }

        preg_match_all('/include:\S+/i', $expected, $m);

        foreach ($m[0] as $include) {
            if (stripos($txt, $include) === false) {
                return false;
            }
        }

        return true;
    }

    protected function normalize(string $value): string
    {
        return Str::lower(preg_replace('/[\s"]+/', '', $value) ?? '');
    }

    /**
     * @return array{key: string, label: string, pass: bool, required: bool, detail: string}
     */
    protected function row(string $key, string $label, bool $pass, bool $required, string $detail): array
    {
        return compact('key', 'label', 'pass', 'required', 'detail');
    }
}
