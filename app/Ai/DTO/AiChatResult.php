<?php

namespace App\Ai\DTO;

class AiChatResult
{
    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public array $usage = [],
        public array $meta = [],
    ) {}
}
