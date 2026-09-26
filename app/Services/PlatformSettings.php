<?php

namespace App\Services;

use App\Ai\AiManager;
use Illuminate\Support\Facades\Crypt;
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
 *  - trial_days                   free-trial length for new workspaces (no free tier)
 *  - ai_enabled                   master switch for all AI features
 *  - ai_*                         per-feature toggles (see AI_FEATURES)
 *  - ai_provider / ai_model / ai_api_key  LLM provider configuration
 */
class PlatformSettings
{
    /**
     * Catalog of AI features that can be toggled from platform admin.
     * Keys are stored as platform_settings rows; labels/descriptions drive the UI.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const AI_FEATURES = [
        'smart_triage' => [
            'label' => 'Smart triage',
            'description' => 'Classify inbound mail by priority, intent, and language.',
        ],
        'reply_draft' => [
            'label' => 'Reply draft',
            'description' => 'Suggest a reply from thread history and mailbox tone.',
        ],
        'thread_summary' => [
            'label' => 'Thread summary',
            'description' => 'One-line summaries and action items for long threads.',
        ],
        'compose_assist' => [
            'label' => 'Compose assist',
            'description' => 'Rewrite, tone, translate, and subject help in compose and templates.',
        ],
        'broadcast_assist' => [
            'label' => 'Broadcast assist',
            'description' => 'Campaign copy and subject-line variants before send.',
        ],
        'automation_smart_steps' => [
            'label' => 'Automation smart steps',
            'description' => 'AI conditions and natural-language workflow steps.',
        ],
        'nl_segments' => [
            'label' => 'Natural-language segments',
            'description' => 'Build audience segments from a plain-language description.',
        ],
        'bounce_explanations' => [
            'label' => 'Bounce explanations',
            'description' => 'Explain bounce/complaint causes and suggest fixes.',
        ],
        'in_app_help' => [
            'label' => 'In-app help',
            'description' => 'Answer setup questions from docs and workspace state.',
        ],
        'abuse_detection' => [
            'label' => 'Abuse detection',
            'description' => 'Flag spam patterns, volume spikes, and webhook failure storms.',
        ],
    ];

    public const AI_PROVIDERS = ['openai', 'anthropic'];

    public const KEYS = [
        'signup_open',
        'platform_name',
        'support_email',
        'default_workspace_provider_id',
        'trial_days',
        'ai_enabled',
        'ai_smart_triage',
        'ai_reply_draft',
        'ai_thread_summary',
        'ai_compose_assist',
        'ai_broadcast_assist',
        'ai_automation_smart_steps',
        'ai_nl_segments',
        'ai_bounce_explanations',
        'ai_in_app_help',
        'ai_abuse_detection',
        'ai_provider',
        'ai_model',
        'ai_api_key',
    ];

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

            if ($key === 'ai_api_key') {
                if ($value === null || $value === '') {
                    continue;
                }

                $value = Crypt::encryptString((string) $value);
            } else {
                $value = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);
            }

            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $this->cache = null;
        $this->applyConfig();
    }

    public function clearAiApiKey(): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'ai_api_key'],
            ['value' => null, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->cache = null;
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

    public function trialDays(): int
    {
        return max(1, (int) $this->get('trial_days', 14));
    }

    /** Master switch: when false, every AI feature is treated as off. */
    public function aiEnabled(): bool
    {
        return $this->boolSetting('ai_enabled', false);
    }

    /**
     * Whether a specific AI feature is on (requires the master switch).
     *
     * @param  key-of<self::AI_FEATURES>  $feature
     */
    public function aiFeatureEnabled(string $feature): bool
    {
        if (! array_key_exists($feature, self::AI_FEATURES)) {
            return false;
        }

        if (! $this->aiEnabled()) {
            return false;
        }

        return $this->boolSetting('ai_'.$feature, false);
    }

    public function aiProvider(): string
    {
        $provider = (string) $this->get('ai_provider', config('maildesk.ai.default_provider', 'openai'));

        return in_array($provider, self::AI_PROVIDERS, true)
            ? $provider
            : (string) config('maildesk.ai.default_provider', 'openai');
    }

    public function aiModel(): ?string
    {
        $model = $this->get('ai_model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    public function aiModelFor(string $provider): string
    {
        if ($model = $this->aiModel()) {
            return $model;
        }

        return (string) (config("maildesk.ai.providers.{$provider}.model")
            ?: config('maildesk.ai.providers.openai.model', 'gpt-4.1-mini'));
    }

    public function aiApiKey(): ?string
    {
        $stored = $this->get('ai_api_key');

        if (is_string($stored) && $stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (Throwable) {
                // Fall through to env if the ciphertext is corrupt.
            }
        }

        $provider = $this->aiProvider();
        $envKey = (string) config("maildesk.ai.providers.{$provider}.api_key", '');

        return $envKey !== '' ? $envKey : null;
    }

    public function aiApiKeySet(): bool
    {
        return filled($this->get('ai_api_key')) || filled($this->aiApiKey());
    }

    /**
     * Snapshot for admin UI and shared Inertia props.
     *
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     model: string|null,
     *     api_key_set: bool,
     *     features: array<string, array{key: string, label: string, description: string, enabled: bool}>
     * }
     */
    public function aiSettings(): array
    {
        $features = [];

        foreach (self::AI_FEATURES as $key => $meta) {
            $features[$key] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => $this->boolSetting('ai_'.$key, false),
            ];
        }

        return [
            'enabled' => $this->aiEnabled(),
            'provider' => $this->aiProvider(),
            'model' => $this->aiModel(),
            'api_key_set' => $this->aiApiKeySet(),
            'features' => $features,
        ];
    }

    /**
     * Effective per-feature flags for workspace UI (master + feature + provider configured).
     *
     * @return array<string, bool>
     */
    public function aiFeatureFlags(): array
    {
        $configured = app(AiManager::class)->configured();
        $flags = [];

        foreach (array_keys(self::AI_FEATURES) as $key) {
            $flags[$key] = $configured && $this->aiFeatureEnabled($key);
        }

        return $flags;
    }

    protected function boolSetting(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return $value !== '0';
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
