<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Models\Message;

class BounceExplanationService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('bounce_explanations') && $this->ai->configured();
    }

    /**
     * @return array{explanation: string, causes: list<string>, fixes: list<string>}
     *
     * @throws AiException
     */
    public function explain(Message $message): array
    {
        if (! $this->enabled()) {
            throw new AiException('Bounce explanations are disabled.');
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $bounce = is_array($meta['bounce'] ?? null) ? $meta['bounce'] : [];
        $reason = (string) ($bounce['reason'] ?? $meta['error'] ?? $message->status);
        $type = (string) ($bounce['type'] ?? ($message->status === 'bounced' ? 'hard' : 'soft'));

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You explain email bounce and complaint events for operators. '
                        .'Respond with JSON only: {"explanation":"...","causes":["..."],"fixes":["..."]}. '
                        .'Be practical and concise.',
                ],
                [
                    'role' => 'user',
                    'content' => "Explain this bounce/complaint.\n"
                        ."Status: {$message->status}\n"
                        ."Provider type: {$type}\n"
                        ."Reason: {$reason}\n"
                        .'To: '.json_encode($message->to)."\n"
                        ."Subject: {$message->subject}",
                ],
            ],
            temperature: 0.2,
            maxTokens: 500,
            options: ['json' => true],
        ));

        $decoded = json_decode($response->content, true);

        if (! is_array($decoded)) {
            throw new AiException('Bounce explanation response was not valid JSON.');
        }

        $explanation = trim((string) ($decoded['explanation'] ?? ''));
        $causes = $this->stringList($decoded['causes'] ?? []);
        $fixes = $this->stringList($decoded['fixes'] ?? []);

        if ($explanation === '') {
            throw new AiException('The AI returned an empty bounce explanation.');
        }

        return [
            'explanation' => mb_substr($explanation, 0, 600),
            'causes' => $causes,
            'fixes' => $fixes,
        ];
    }

    /**
     * @return list<string>
     */
    protected function stringList(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => mb_substr(trim($item), 0, 200))
            ->take(6)
            ->values()
            ->all();
    }
}
