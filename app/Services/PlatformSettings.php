<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Key/value platform settings managed from /admin/settings.
 *
 * Only settings the application actually reads are supported:
 *  - signup_open                  gates the public /register routes
 *  - platform_name                overrides config('app.name')
 *  - support_email                overrides config('maildesk.support_email') (Help page)
 *  - default_workspace_provider_id  mail provider assigned to newly created workspaces
 */
class PlatformSettings
{
    public const KEYS = ['signup_open', 'platform_name', 'support_email', 'default_workspace_provider_id'];

    /** @var array<string, string|null>|null */
    protected ?array $cache = null;

    /** @return array<string, string|null> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $this->cache = DB::table('platform_settings')->pluck('value', 'key')->all();
        } catch (Throwable) {
            // Table not migrated yet (fresh install / during migrate): use defaults.
            return [];
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            $value = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);

            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $this->cache = null;
        $this->applyConfig();
    }

    public function signupOpen(): bool
    {
        return $this->get('signup_open', '1') !== '0';
    }

    public function defaultWorkspaceProviderId(): ?int
    {
        $id = $this->get('default_workspace_provider_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** Overlay stored values onto the config keys the app reads. */
    public function applyConfig(): void
    {
        if ($name = $this->get('platform_name')) {
            config(['app.name' => $name]);
        }

        if ($email = $this->get('support_email')) {
            config(['maildesk.support_email' => $email]);
        }
    }
}
