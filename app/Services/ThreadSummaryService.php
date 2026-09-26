<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Models\Message;
use App\Models\Thread;

class ThreadSummaryService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('thread_summary') && $this->ai->configured();
    }

    /**
     * @return array{summary: string, action_items: list<string>}
     *
     * @throws AiException
     */
    public function summarize(Thread $thread): array
    {
        if (! $this->enabled()) {
            throw new AiException('Thread summary is disabled.');
        }

        $thread->loadMissing(['messages' => fn ($query) => $query->orderBy('created_at')]);

        if ($thread->messages->isEmpty()) {
            throw new AiException('This conversation has no messages to summarize.');
        }

        $transcript = $thread->messages
            ->take(-12)
            ->map(function (Message $message) {
                $body = trim((string) ($message->text_body ?: strip_tags((string) $message->html_body)));
                $body = mb_substr($body, 0, 1200);
                $who = $message->direction === 'inbound'
                    ? 'Customer ('.$message->from_email.')'
                    : 'Us';

                return "{$who}:\n".($body !== '' ? $body : '(empty)');
            })
            ->implode("\n\n");

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You summarize business email threads. '
                        .'Respond with JSON only using keys summary (one short sentence) '
                        .'and action_items (array of short strings, may be empty).',
                ],
                [
                    'role' => 'user',
                    'content' => "Subject: {$thread->subject}\n\nConversation:\n{$transcript}\n\n"
                        .'Summarize this email thread and list action items.',
                ],
            ],
            temperature: 0.2,
            maxTokens: 400,
            options: ['json' => true],
        ));

        $decoded = json_decode($response->content, true);

        if (! is_array($decoded)) {
            throw new AiException('Thread summary response was not valid JSON.');
        }

        $summary = trim((string) ($decoded['summary'] ?? ''));
        $items = collect($decoded['action_items'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => mb_substr(trim($item), 0, 200))
            ->take(8)
            ->values()
            ->all();

        if ($summary === '') {
            throw new AiException('The AI returned an empty thread summary.');
        }

        $payload = [
            'summary' => mb_substr($summary, 0, 400),
            'action_items' => $items,
            'provider' => $response->provider,
            'model' => $response->model,
            'summarized_at' => now()->toIso8601String(),
        ];

        $thread->forceFill([
            'ai' => array_merge($thread->ai ?? [], [
                'summary' => $payload['summary'],
                'action_items' => $payload['action_items'],
                'summary_meta' => [
                    'provider' => $payload['provider'],
                    'model' => $payload['model'],
                    'summarized_at' => $payload['summarized_at'],
                ],
            ]),
        ])->save();

        return [
            'summary' => $payload['summary'],
            'action_items' => $payload['action_items'],
        ];
    }
}
