<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Models\Organization;

class InAppHelpService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('in_app_help') && $this->ai->configured();
    }

    /**
     * @return array{answer: string}
     *
     * @throws AiException
     */
    public function answer(string $question, ?Organization $organization = null): array
    {
        if (! $this->enabled()) {
            throw new AiException('In-app help is disabled.');
        }

        $question = trim($question);

        if ($question === '') {
            throw new AiException('Ask a setup question first.');
        }

        $context = [
            'workspace' => $organization?->name,
            'has_verified_domain' => $organization
                ? $organization->domains()->where('status', 'verified')->exists()
                : false,
            'has_api_key' => $organization
                ? $organization->apiKeys()->whereNull('revoked_at')->exists()
                : false,
            'has_webhook' => $organization
                ? $organization->webhooks()->where('is_active', true)->exists()
                : false,
            'docs_topics' => [
                'Domains and DNS verification',
                'API keys and sending via API',
                'Compose and inbox replies',
                'Audience contacts and segments',
                'Broadcasts and automations',
                'Webhooks for delivery events',
                'Billing and trials',
            ],
        ];

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You are MailDesk in-app help. Answer setup questions briefly and accurately '
                        .'using the product docs topics and workspace state. If something is not available, say so. '
                        .'Respond with plain text only (no markdown fences).',
                ],
                [
                    'role' => 'user',
                    'content' => "Workspace context JSON:\n".json_encode($context)
                        ."\n\nSetup question:\n{$question}",
                ],
            ],
            temperature: 0.3,
            maxTokens: 500,
        ));

        $answer = trim($response->content);
        $answer = preg_replace('/^```(?:text)?\s*/i', '', $answer) ?? $answer;
        $answer = preg_replace('/\s*```$/', '', $answer) ?? $answer;
        $answer = trim($answer);

        if ($answer === '') {
            throw new AiException('The AI returned an empty help answer.');
        }

        return [
            'answer' => mb_substr($answer, 0, 2000),
        ];
    }
}
