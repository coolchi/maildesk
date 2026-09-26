<?php

namespace App\Ai\Contracts;

use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\AiChatResult;

interface AiProvider
{
    public function name(): string;

    public function configured(): bool;

    public function chat(AiChatRequest $request): AiChatResult;
}
