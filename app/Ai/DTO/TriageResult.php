<?php

namespace App\Ai\DTO;

class TriageResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $priority,
        public string $intent,
        public string $language,
        public array $meta = [],
    ) {}

    /**
     * @return array{
     *     priority: string,
     *     intent: string,
     *     language: string,
     *     provider: string|null,
     *     model: string|null,
     *     triaged_at: string
     * }
     */
    public function toThreadAiArray(): array
    {
        return [
            'priority' => $this->priority,
            'intent' => $this->intent,
            'language' => $this->language,
            'provider' => $this->meta['provider'] ?? null,
            'model' => $this->meta['model'] ?? null,
            'triaged_at' => now()->toIso8601String(),
        ];
    }
}
