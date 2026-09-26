<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider;
use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\AiChatResult;
use App\Ai\Exceptions\AiException;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'https://api.anthropic.com',
        protected string $apiVersion = '2023-06-01',
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function chat(AiChatRequest $request): AiChatResult
    {
        if (! $this->configured()) {
            throw new AiException('Anthropic API key is not configured.');
        }

        $model = $request->model ?: $this->model;
        $system = '';
        $messages = [];

        foreach ($request->messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $system = trim($system.' '.($message['content'] ?? ''));

                continue;
            }

            $messages[] = [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($message['content'] ?? ''),
            ];
        }

        if ($messages === []) {
            throw new AiException('Anthropic chat requires at least one user message.');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? 800,
            'temperature' => $request->temperature,
        ];

        if ($system !== '') {
            $payload['system'] = trim($system);
        }

        $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
            ])
            ->acceptJson()
            ->timeout(45)
            ->post('/v1/messages', $payload);

        if ($response->failed()) {
            throw new AiException('Anthropic request failed: '.$response->body());
        }

        $blocks = data_get($response->json(), 'content', []);
        $content = collect(is_array($blocks) ? $blocks : [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        if ($content === '') {
            throw new AiException('Anthropic returned an empty response.');
        }

        return new AiChatResult(
            content: $content,
            provider: $this->name(),
            model: (string) data_get($response->json(), 'model', $model),
            usage: (array) data_get($response->json(), 'usage', []),
        );
    }
}
