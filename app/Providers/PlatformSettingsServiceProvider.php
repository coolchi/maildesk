<?php

namespace App\Providers;

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

        // New workspaces get the admin-chosen provider when it is still active,
        // otherwise the active platform default (is_default).
        Organization::creating(function (Organization $organization) use ($settings): void {
            if ($organization->mail_provider_id) {
                return;
            }

            $provider = $settings->workspaceProvider();
            if ($provider) {
                $organization->mail_provider_id = $provider->id;
                $organization->default_provider = $provider->driver;
            }
        });
    }
}
