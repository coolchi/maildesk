<?php

namespace Tests\Unit;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Ai\Providers\FakeAiProvider;
use App\Ai\Providers\OpenAiProvider;
use App\Ai\Providers\UnsupportedAiProvider;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_config_forces_fake_provider(): void
    {
        config(['maildesk.ai.fake' => true]);

        $driver = app(AiManager::class)->driver('openai');

        $this->assertInstanceOf(FakeAiProvider::class, $driver);
    }

    public function test_resolves_openai_when_fake_is_disabled(): void
    {
        config([
            'maildesk.ai.fake' => false,
            'maildesk.ai.providers.openai.api_key' => 'sk-env',
            'maildesk.ai.providers.openai.model' => 'gpt-4.1-mini',
        ]);

        app(PlatformSettings::class)->set([
            'ai_provider' => 'openai',
        ]);

        $driver = app(AiManager::class)->driver();

        $this->assertInstanceOf(OpenAiProvider::class, $driver);
        $this->assertTrue($driver->configured());
    }

    public function test_unknown_provider_is_unsupported(): void
    {
        config(['maildesk.ai.fake' => false]);

        $driver = app(AiManager::class)->driver('not-real');

        $this->assertInstanceOf(UnsupportedAiProvider::class, $driver);
        $this->expectException(AiException::class);
        $driver->chat(new AiChatRequest(messages: [['role' => 'user', 'content' => 'hi']]));
    }

    public function test_openai_provider_posts_chat_completions(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4.1-mini',
                'choices' => [
                    ['message' => ['content' => '{"priority":"normal","intent":"support","language":"en"}']],
                ],
                'usage' => ['total_tokens' => 12],
            ]),
        ]);

        $provider = new OpenAiProvider('sk-test', 'gpt-4.1-mini');
        $result = $provider->chat(new AiChatRequest(
            messages: [['role' => 'user', 'content' => 'hello']],
            options: ['json' => true],
        ));

        $this->assertSame('openai', $result->provider);
        $this->assertStringContainsString('priority', $result->content);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request['response_format']['type'] === 'json_object');
    }
}
