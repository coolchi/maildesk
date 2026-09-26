<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;

class NlSegmentService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
        public SegmentMembership $membership,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('nl_segments') && $this->ai->configured();
    }

    /**
     * @return array{name: string, description: ?string, rules: list<array{field: string, op: string, value: string}>}
     *
     * @throws AiException
     */
    public function fromPrompt(string $prompt): array
    {
        if (! $this->enabled()) {
            throw new AiException('Natural-language segments are disabled.');
        }

        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new AiException('Describe the audience segment you want.');
        }

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You convert plain-language audience descriptions into MailDesk segment rules. '
                        .'Respond with JSON only: {"name":"...","description":"...","rules":[{"field":"meta.status|email_domain","op":"eq|contains","value":"..."}]}. '
                        .'meta.status values are typically subscribed or unsubscribed. '
                        .'email_domain value is a domain like acme.com. Prefer 1-3 rules.',
                ],
                [
                    'role' => 'user',
                    'content' => "Natural-language segment request:\n{$prompt}",
                ],
            ],
            temperature: 0.2,
            maxTokens: 400,
            options: ['json' => true],
        ));

        $decoded = json_decode($response->content, true);

        if (! is_array($decoded)) {
            throw new AiException('Segment response was not valid JSON.');
        }

        $rules = $this->membership->normalizeRules($decoded['rules'] ?? []);

        if ($rules === []) {
            // Sensible default when the model returns nothing usable.
            $rules = [
                ['field' => 'meta.status', 'op' => 'eq', 'value' => 'subscribed'],
            ];
        }

        $name = trim((string) ($decoded['name'] ?? 'AI Segment'));
        if ($name === '') {
            $name = 'AI Segment';
        }

        $description = trim((string) ($decoded['description'] ?? $prompt));

        return [
            'name' => mb_substr($name, 0, 120),
            'description' => $description !== '' ? mb_substr($description, 0, 255) : null,
            'rules' => $rules,
        ];
    }
}
