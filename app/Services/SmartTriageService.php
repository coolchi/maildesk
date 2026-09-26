<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\DTO\TriageResult;
use App\Ai\Exceptions\AiException;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmartTriageService
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const INTENTS = ['support', 'sales', 'billing', 'spam', 'other'];

    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function shouldTriage(): bool
    {
        return $this->settings->aiFeatureEnabled('smart_triage') && $this->ai->configured();
    }

    public function classifyMessage(Message $message): ?TriageResult
    {
        if (! $this->shouldTriage()) {
            return null;
        }

        $message->loadMissing('thread');
        $thread = $message->thread;

        if ($thread === null) {
            return null;
        }

        try {
            $result = $this->askModel($message);
        } catch (Throwable $e) {
            Log::warning('Smart triage failed', [
                'message_id' => $message->id,
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $thread->forceFill([
            'ai' => array_merge($thread->ai ?? [], $result->toThreadAiArray()),
        ])->save();

        return $result;
    }

    protected function askModel(Message $message): TriageResult
    {
        $body = trim((string) ($message->text_body ?: strip_tags((string) $message->html_body)));
        $body = mb_substr($body, 0, 4000);

        $provider = $this->ai->driver();
        $response = $provider->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You classify inbound business emails. '
                        .'Respond with JSON only using keys priority, intent, language. '
                        .'priority must be one of: low, normal, high, urgent. '
                        .'intent must be one of: support, sales, billing, spam, other. '
                        .'language is a short ISO 639-1 code such as en, fr, or es.',
                ],
                [
                    'role' => 'user',
                    'content' => "From: {$message->from_email}\n"
                        ."Subject: {$message->subject}\n\n"
                        .($body !== '' ? $body : '(empty body)'),
                ],
            ],
            temperature: 0.1,
            maxTokens: 200,
            options: ['json' => true],
        ));

        $decoded = json_decode($response->content, true);

        if (! is_array($decoded)) {
            throw new AiException('Triage response was not valid JSON.');
        }

        $priority = strtolower((string) ($decoded['priority'] ?? 'normal'));
        $intent = strtolower((string) ($decoded['intent'] ?? 'other'));
        $language = strtolower((string) ($decoded['language'] ?? 'en'));

        if (! in_array($priority, self::PRIORITIES, true)) {
            $priority = 'normal';
        }

        if (! in_array($intent, self::INTENTS, true)) {
            $intent = 'other';
        }

        $language = preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language) ? $language : 'en';

        return new TriageResult(
            priority: $priority,
            intent: $intent,
            language: $language,
            meta: [
                'provider' => $response->provider,
                'model' => $response->model,
            ],
        );
    }
}
