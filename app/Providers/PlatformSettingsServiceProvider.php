<?php

namespace App\Providers;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Services\PlatformSettings;
use Illuminate\Support\ServiceProvider;

class PlatformSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);
    }

    public function boot(PlatformSettings $settings): void
    {
        $settings->applyConfig();

        // New workspaces get the admin-chosen provider (only when one is configured
        // and still active; otherwise behaviour is unchanged).
        Organization::creating(function (Organization $organization) use ($settings): void {
            if ($organization->mail_provider_id) {
                return;
            }

            $providerId = $settings->defaultWorkspaceProviderId();
            if (! $providerId) {
                return;
            }

            $provider = MailProvider::query()->whereKey($providerId)->where('status', '!=', 'disabled')->first();
            if ($provider) {
                $organization->mail_provider_id = $provider->id;
                $organization->default_provider = $provider->driver;
            }
        });
    }
}
