<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider;
use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\AiChatResult;
use App\Ai\Exceptions\AiException;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProvider
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function chat(AiChatRequest $request): AiChatResult
    {
        if (! $this->configured()) {
            throw new AiException('OpenAI API key is not configured.');
        }

        $model = $request->model ?: $this->model;
        $payload = [
            'model' => $model,
            'messages' => $request->messages,
            'temperature' => $request->temperature,
        ];

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if (($request->options['json'] ?? false) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(45)
            ->post('/chat/completions', $payload);

        if ($response->failed()) {
            throw new AiException('OpenAI request failed: '.$response->body());
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        if ($content === '') {
            throw new AiException('OpenAI returned an empty response.');
        }

        return new AiChatResult(
            content: $content,
            provider: $this->name(),
            model: (string) data_get($response->json(), 'model', $model),
            usage: (array) data_get($response->json(), 'usage', []),
        );
    }
}
