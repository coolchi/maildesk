<?php

namespace App\Ai;

use App\Ai\Contracts\AiProvider;
use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\FakeAiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\UnsupportedAiProvider;
use App\Services\PlatformSettings;

class AiManager
{
    public function __construct(protected PlatformSettings $settings) {}

    /**
     * Resolve the active platform AI provider.
     *
     * Precedence: maildesk.ai.fake → platform setting → config default.
     */
    public function driver(?string $name = null): AiProvider
    {
        if (config('maildesk.ai.fake')) {
            return new FakeAiProvider(
                model: (string) config('maildesk.ai.providers.fake.model', 'fake-model'),
            );
        }

        $name = $name ?: $this->settings->aiProvider();

        return match ($name) {
            'openai' => new OpenAiProvider(
                apiKey: $this->settings->aiApiKey() ?? '',
                model: $this->settings->aiModelFor('openai'),
                baseUrl: (string) config('maildesk.ai.providers.openai.base_url', 'https://api.openai.com/v1'),
            ),
            'anthropic' => new AnthropicProvider(
                apiKey: $this->settings->aiApiKey() ?? '',
                model: $this->settings->aiModelFor('anthropic'),
                baseUrl: (string) config('maildesk.ai.providers.anthropic.base_url', 'https://api.anthropic.com'),
                apiVersion: (string) config('maildesk.ai.providers.anthropic.api_version', '2023-06-01'),
            ),
            'fake' => new FakeAiProvider(
                model: (string) config('maildesk.ai.providers.fake.model', 'fake-model'),
            ),
            default => new UnsupportedAiProvider($name),
        };
    }

    public function configured(?string $name = null): bool
    {
        return $this->driver($name)->configured();
    }

    /**
     * @return list<array{key: string, label: string, default_model: string}>
     */
    public function availableProviders(): array
    {
        return [
            [
                'key' => 'openai',
                'label' => 'OpenAI',
                'default_model' => (string) config('maildesk.ai.providers.openai.model', 'gpt-4.1-mini'),
            ],
            [
                'key' => 'anthropic',
                'label' => 'Anthropic',
                'default_model' => (string) config('maildesk.ai.providers.anthropic.model', 'claude-sonnet-4-5'),
            ],
        ];
    }
}
