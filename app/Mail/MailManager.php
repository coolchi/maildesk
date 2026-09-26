<?php

namespace App\Mail;

use App\Mail\Contracts\MailProvider;
use App\Mail\Providers\ArrayProvider;
use App\Mail\Providers\ResendProvider;
use App\Mail\Providers\SmtpProvider;
use App\Mail\Providers\UnsupportedProvider;
use App\Models\MailProvider as PlatformMailProvider;
use App\Models\Organization;
use App\Models\ProviderConfig;

class MailManager
{
    /**
     * Which provider sends for a workspace:
     *
     *  1. The workspace's own SMTP settings (Settings → SMTP), when saved with
     *     "Use SMTP for sending" on and a host → smtp with ONLY those credentials.
     *  2. Otherwise the platform provider assigned by an admin (driver), e.g.
     *     resend, or smtp with the admin provider's HOST/PORT/USERNAME/… config
     *     layered over MAILDESK_SMTP_* env values.
     *  3. Otherwise the workspace default_provider / maildesk.default_provider.
     *
     * With maildesk.fake_send on (local/testing) the chosen driver is replaced
     * by an in-memory ArrayProvider that reports the same name.
     */
    public function forOrganization(Organization $organization): MailProvider
    {
        $organization->loadMissing('mailProvider');

        return $this->driver($this->driverNameFor($organization), $organization, $this->platformProviderFor($organization));
    }

    /**
     * The workspace's assigned platform provider, or, when that one is
     * disabled, the active platform default (is_default). Null when neither.
     */
    public function platformProviderFor(Organization $organization): ?PlatformMailProvider
    {
        $organization->loadMissing('mailProvider');
        $assigned = $organization->mailProvider;

        if ($assigned === null || $assigned->status === 'active') {
            return $assigned;
        }

        return PlatformMailProvider::query()->where('is_default', true)->where('status', 'active')->first();
    }

    /**
     * default_provider should hold a driver name, but older rows stored a
     * platform provider KEY (e.g. resend_66f…); map those to their driver.
     */
    protected function normalizeDriverName(?string $name): ?string
    {
        if ($name === null || $name === '' || in_array($name, ['resend', 'smtp', 'array', 'log'], true)) {
            return $name;
        }

        return PlatformMailProvider::query()->where('key', $name)->value('driver') ?: $name;
    }

    public function driverNameFor(Organization $organization): string
    {
        if ($this->organizationSmtpConfig($organization) !== null) {
            return 'smtp';
        }

        return (string) ($this->platformProviderFor($organization)?->driver
            ?: ($this->normalizeDriverName($organization->default_provider) ?: config('maildesk.default_provider')));
    }

    /**
     * The workspace's enabled SMTP settings, or null when it has none.
     */
    public function organizationSmtpConfig(Organization $organization): ?ProviderConfig
    {
        /** @var ProviderConfig|null $config */
        $config = $organization->providerConfigs()
            ->where('provider', 'smtp')
            ->where('is_active', true)
            ->first();

        return $config && trim((string) ($config->credentials['host'] ?? '')) !== '' ? $config : null;
    }

    /**
     * Resolved SMTP settings for a workspace (see forOrganization for precedence).
     *
     * @return array<string, mixed>
     */
    public function smtpConfigFor(?Organization $organization, ?PlatformMailProvider $platform = null): array
    {
        if ($organization && ($config = $this->organizationSmtpConfig($organization))) {
            // Never mix the platform's username/password into a workspace's own server.
            return array_merge(
                ['port' => 587, 'encryption' => 'tls', 'username' => null, 'password' => null],
                array_filter($config->credentials ?? [], fn ($value) => $value !== null && $value !== ''),
                ['driver' => 'smtp'],
            );
        }

        $defaults = array_filter(config('maildesk.providers.smtp', []), fn ($value) => $value !== null && $value !== '');
        $platform ??= $organization?->mailProvider;

        if ($platform && ($platform->driver === 'smtp' || $platform->type === 'smtp' || $platform->key === 'smtp')) {
            return array_merge($defaults, $this->credentialsFromPlatform($platform));
        }

        return $defaults;
    }

    public function driver(string $provider, ?Organization $organization = null, ?PlatformMailProvider $platform = null): MailProvider
    {
        if (config('maildesk.fake_send')) {
            return new ArrayProvider($provider);
        }

        $credentials = $provider === 'smtp' ? [] : $this->resolveCredentials($provider, $organization, $platform);

        return match ($provider) {
            'resend' => new ResendProvider($credentials['api_key'] ?? null),
            'smtp' => new SmtpProvider($this->smtpConfigFor($organization, $platform)),
            'array', 'log' => new ArrayProvider($provider),
            default => new UnsupportedProvider($provider),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveCredentials(string $provider, ?Organization $organization, ?PlatformMailProvider $platform): array
    {
        if ($organization) {
            /** @var ProviderConfig|null $config */
            $config = $organization->providerConfigs()
                ->where('provider', $provider)
                ->where('is_active', true)
                ->first();

            if (! empty($config?->credentials)) {
                return $config->credentials;
            }
        }

        $platform ??= $organization?->mailProvider;

        if ($platform && ($platform->driver === $provider || $platform->key === $provider)) {
            return $this->credentialsFromPlatform($platform);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentialsFromPlatform(PlatformMailProvider $platform): array
    {
        $credentials = [];

        foreach ($platform->config ?? [] as $row) {
            $key = strtoupper((string) ($row['key'] ?? ''));
            $value = $row['value'] ?? null;

            if ($key === '' || $value === null || $value === '') {
                continue;
            }

            // Skip masked seed placeholders.
            if (is_string($value) && str_contains($value, '•')) {
                continue;
            }

            $map = match ($key) {
                'API_KEY', 'SERVER_TOKEN' => 'api_key',
                'HOST' => 'host',
                'PORT' => 'port',
                'USERNAME' => 'username',
                'PASSWORD' => 'password',
                'ENCRYPTION' => 'encryption',
                default => null,
            };

            if ($map) {
                $credentials[$map] = $value;
            }
        }

        return $credentials;
    }
}
