<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;

class AutomationSmartStepsService
{
    public const TRIGGERS = ['user.created', 'email.opened', 'contact.added'];

    public const STEP_TYPES = ['trigger', 'delay', 'email'];

    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('automation_smart_steps') && $this->ai->configured();
    }

    /**
     * @return array{name: string, trigger: string, steps: list<array{type: string, label: string}>}
     *
     * @throws AiException
     */
    public function fromPrompt(string $prompt): array
    {
        if (! $this->enabled()) {
            throw new AiException('Automation smart steps are disabled.');
        }

        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new AiException('Describe the automation you want.');
        }

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You design simple email automation workflows. '
                        .'Respond with JSON only: {"name":"...","trigger":"user.created|email.opened|contact.added",'
                        .'"steps":[{"type":"trigger|delay|email","label":"..."}]}. '
                        .'First step must be type trigger. Include at least one email step. '
                        .'Delay labels like "Wait 1 hour" or "Wait 1 day".',
                ],
                [
                    'role' => 'user',
                    'content' => "Natural-language workflow request:\n{$prompt}",
                ],
            ],
            temperature: 0.3,
            maxTokens: 600,
            options: ['json' => true],
        ));

        $decoded = json_decode($response->content, true);

        if (! is_array($decoded)) {
            throw new AiException('Automation response was not valid JSON.');
        }

        $trigger = (string) ($decoded['trigger'] ?? 'contact.added');
        if (! in_array($trigger, self::TRIGGERS, true)) {
            $trigger = 'contact.added';
        }

        $name = trim((string) ($decoded['name'] ?? 'AI Automation'));
        if ($name === '') {
            $name = 'AI Automation';
        }

        $steps = collect($decoded['steps'] ?? [])
            ->filter(fn ($step) => is_array($step))
            ->map(function (array $step) {
                $type = (string) ($step['type'] ?? '');
                $label = trim((string) ($step['label'] ?? ''));

                if (! in_array($type, self::STEP_TYPES, true) || $label === '') {
                    return null;
                }

                return [
                    'type' => $type,
                    'label' => mb_substr($label, 0, 120),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($steps === [] || ($steps[0]['type'] ?? null) !== 'trigger') {
            $steps = [
                ['type' => 'trigger', 'label' => "When {$trigger}"],
                ['type' => 'email', 'label' => 'Send welcome email'],
            ];
        } else {
            $steps[0]['label'] = "When {$trigger}";
        }

        if (! collect($steps)->contains(fn (array $step) => $step['type'] === 'email')) {
            $steps[] = ['type' => 'email', 'label' => 'Send follow-up'];
        }

        return [
            'name' => mb_substr($name, 0, 120),
            'trigger' => $trigger,
            'steps' => array_values($steps),
        ];
    }
}
