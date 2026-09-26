<?php

namespace Tests\Unit;

use App\Ai\DTO\AiChatRequest;
use App\Ai\Providers\FakeAiProvider;
use PHPUnit\Framework\TestCase;

class AiProviderTest extends TestCase
{
    public function test_fake_provider_detects_urgent_mail(): void
    {
        $provider = new FakeAiProvider;
        $result = $provider->chat(new AiChatRequest(
            messages: [['role' => 'user', 'content' => 'Subject: Urgent ASAP']],
        ));

        $this->assertSame('fake', $result->provider);
        $this->assertStringContainsString('"priority":"urgent"', $result->content);
    }

    public function test_fake_provider_detects_sales_mail(): void
    {
        $provider = new FakeAiProvider;
        $result = $provider->chat(new AiChatRequest(
            messages: [['role' => 'user', 'content' => 'Interested in pricing and a demo']],
        ));

        $this->assertStringContainsString('"intent":"sales"', $result->content);
    }
}
