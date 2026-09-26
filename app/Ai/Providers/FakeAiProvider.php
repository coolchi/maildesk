<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider;
use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\AiChatResult;

/**
 * Deterministic in-memory provider for local/testing (maildesk.ai.fake).
 */
class FakeAiProvider implements AiProvider
{
    /** @var list<AiChatRequest> */
    public array $requests = [];

    public function __construct(
        protected string $content = '{"priority":"normal","intent":"support","language":"en"}',
        protected string $model = 'fake-model',
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function configured(): bool
    {
        return true;
    }

    public function chat(AiChatRequest $request): AiChatResult
    {
        $this->requests[] = $request;

        $haystack = collect($request->messages)->pluck('content')->implode(' ');
        $content = $this->content;

        if (
            stripos($haystack, 'draft a reply') !== false
            || stripos($haystack, 'Draft a reply from us') !== false
            || stripos($haystack, 'Respond with HTML only') !== false
        ) {
            $content = '<p>Thanks for reaching out. We are looking into this and will follow up shortly.</p>';
        } elseif (stripos($haystack, 'urgent') !== false || stripos($haystack, 'asap') !== false) {
            $content = '{"priority":"urgent","intent":"support","language":"en"}';
        } elseif (stripos($haystack, 'unsubscribe') !== false || stripos($haystack, 'buy now') !== false) {
            $content = '{"priority":"low","intent":"spam","language":"en"}';
        } elseif (stripos($haystack, 'pricing') !== false || stripos($haystack, 'demo') !== false) {
            $content = '{"priority":"normal","intent":"sales","language":"en"}';
        }

        return new AiChatResult(
            content: $content,
            provider: $this->name(),
            model: $this->model,
        );
    }
}
