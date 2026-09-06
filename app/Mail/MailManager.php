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
    public function forOrganization(Organization $organization): MailProvider
    {
        $organization->loadMissing('mailProvider');

        $platform = $organization->mailProvider;
        $provider = $platform?->driver
            ?: ($organization->default_provider ?: config('maildesk.default_provider'));

        return $this->driver($provider, $organization, $platform);
    }

    public function driver(string $provider, ?Organization $organization = null, ?PlatformMailProvider $platform = null): MailProvider
    {
        if (config('maildesk.fake_send')) {
            return new ArrayProvider($provider);
        }

        $credentials = $this->resolveCredentials($provider, $organization, $platform);

        return match ($provider) {
            'resend' => new ResendProvider($credentials['api_key'] ?? null),
            'smtp' => new SmtpProvider(array_merge(config('maildesk.providers.smtp', []), $credentials)),
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
