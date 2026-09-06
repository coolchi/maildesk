<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationHost;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantResolver
{
    /**
     * @return list<string>
     */
    public function centralHosts(): array
    {
        $configured = config('maildesk.central_domains', []);

        if (! is_array($configured) || $configured === []) {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return array_values(array_filter([(string) $host]));
        }

        return array_values(array_filter(array_map('strval', $configured)));
    }

    public function baseDomain(): string
    {
        $base = (string) config('maildesk.base_domain', '');

        if ($base !== '') {
            return Str::lower($base);
        }

        $centrals = $this->centralHosts();

        return Str::lower($centrals[0] ?? 'localhost');
    }

    public function isCentralHost(string $host): bool
    {
        $host = Str::lower($host);

        foreach ($this->centralHosts() as $central) {
            if ($host === Str::lower($central)) {
                return true;
            }
        }

        return false;
    }

    public function hostFromRequest(Request $request): string
    {
        return Str::lower($request->getHost());
    }

    public function resolveFromHost(string $host): ?Organization
    {
        $host = Str::lower($host);

        if ($this->isCentralHost($host)) {
            return null;
        }

        $byHost = OrganizationHost::query()
            ->whereRaw('LOWER(host) = ?', [$host])
            ->whereIn('status', ['active', 'provisioning', 'pending_dns'])
            ->with(['organization.mailProvider'])
            ->first();

        if ($byHost?->organization) {
            return $byHost->organization;
        }

        $org = Organization::query()
            ->with('mailProvider')
            ->whereRaw('LOWER(custom_domain) = ?', [$host])
            ->first();

        if ($org) {
            return $org;
        }

        $base = $this->baseDomain();
        $suffix = '.'.$base;

        if (! Str::endsWith($host, $suffix)) {
            return null;
        }

        $subdomain = Str::before($host, $suffix);

        if ($subdomain === '' || str_contains($subdomain, '.')) {
            return null;
        }

        return Organization::query()
            ->with('mailProvider')
            ->whereRaw('LOWER(subdomain) = ?', [$subdomain])
            ->first();
    }

    public function resolveForUser(Request $request, $user): ?Organization
    {
        $host = $this->hostFromRequest($request);
        $fromHost = $this->resolveFromHost($host);

        if ($fromHost) {
            if ($user->isPlatformAdmin() || $user->organizations()->whereKey($fromHost->id)->exists()) {
                return $fromHost->loadMissing('mailProvider');
            }

            return null;
        }

        $sessionId = $request->session()->get('current_organization_id');

        if ($sessionId) {
            $fromSession = $user->organizations()
                ->with('mailProvider')
                ->whereKey($sessionId)
                ->first();

            if ($fromSession) {
                return $fromSession;
            }

            if ($user->isPlatformAdmin()) {
                $adminOrg = Organization::query()
                    ->with('mailProvider')
                    ->find($sessionId);

                if ($adminOrg) {
                    return $adminOrg;
                }
            }
        }

        $first = $user->organizations()->with('mailProvider')->orderBy('name')->first();

        if ($first) {
            return $first;
        }

        if ($user->isPlatformAdmin()) {
            return Organization::query()->with('mailProvider')->orderBy('name')->first();
        }

        return null;
    }

    public function workspaceUrl(Organization $organization, ?string $path = '/'): string
    {
        $path = '/'.ltrim($path ?: '/', '/');
        if ($path === '//') {
            $path = '/';
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $organization->subdomain
            ? $organization->subdomain.'.'.$this->baseDomain()
            : ($this->centralHosts()[0] ?? $this->baseDomain());

        return $scheme.'://'.$host.$path;
    }

    /**
     * Shared session cookies (SESSION_DOMAIN) allow login once on the apex
     * host and stay authenticated on tenant subdomains.
     */
    public function sharesSessionAcrossSubdomains(): bool
    {
        $domain = config('session.domain');

        return filled($domain) && $domain !== 'null';
    }

    /**
     * Cookie Domain value for subdomain SSO, e.g. ".maildesk.test".
     */
    public function sessionCookieDomain(): ?string
    {
        if (! $this->sharesSessionAcrossSubdomains()) {
            return null;
        }

        $domain = ltrim((string) config('session.domain'), '.');

        return $domain === '' ? null : '.'.$domain;
    }
}
