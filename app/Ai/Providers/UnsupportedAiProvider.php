<?php

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider;
use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\AiChatResult;
use App\Ai\Exceptions\AiException;

class UnsupportedAiProvider implements AiProvider
{
    public function __construct(protected string $driver) {}

    public function name(): string
    {
        return $this->driver;
    }

    public function configured(): bool
    {
        return false;
    }

    public function chat(AiChatRequest $request): AiChatResult
    {
        throw new AiException("AI provider [{$this->driver}] is not supported.");
    }
}
