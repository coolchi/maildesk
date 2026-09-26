<?php

namespace App\Ai\DTO;

class AiChatRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public array $messages,
        public ?string $model = null,
        public float $temperature = 0.2,
        public ?int $maxTokens = 800,
        public array $options = [],
    ) {}
}
