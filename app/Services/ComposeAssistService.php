<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Support\EmailHtmlSanitizer;

class ComposeAssistService
{
    public const ACTIONS = ['rewrite', 'tone', 'shorten', 'translate', 'subject'];

    public const TONES = ['friendly', 'formal', 'concise', 'persuasive'];

    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('compose_assist') && $this->ai->configured();
    }

    /**
     * @return array{html?: string, subject?: string, subjects?: list<string>}
     *
     * @throws AiException
     */
    public function assist(string $action, string $html, ?string $subject = null, ?string $tone = null, ?string $language = null): array
    {
        if (! $this->enabled()) {
            throw new AiException('Compose assist is disabled.');
        }

        if (! in_array($action, self::ACTIONS, true)) {
            throw new AiException('Unsupported compose assist action.');
        }

        $plain = trim(strip_tags($html));
        if ($action !== 'subject' && $plain === '') {
            throw new AiException('Write some message content first.');
        }

        $tone = in_array($tone, self::TONES, true) ? $tone : 'friendly';
        $language = filled($language) ? preg_replace('/[^a-zA-Z\-]/', '', (string) $language) : 'en';
        $language = $language !== '' ? strtolower((string) $language) : 'en';

        $instruction = match ($action) {
            'rewrite' => 'Rewrite this email for clarity while keeping the meaning. Respond with HTML only using simple <p> tags.',
            'tone' => "Rewrite this email in a {$tone} tone. Respond with HTML only using simple <p> tags.",
            'shorten' => 'Shorten this email while keeping the key points. Respond with HTML only using simple <p> tags.',
            'translate' => "Translate this email to language code {$language}. Respond with HTML only using simple <p> tags.",
            'subject' => 'Suggest 3 concise subject lines for this email. Respond with JSON only: {"subjects":["...","...","..."]}.',
        };

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You assist with composing business emails. '.$instruction
                        .' Do not include markdown fences or commentary.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Compose assist request.'
                        ."\nAction: {$action}"
                        .(filled($subject) ? "\nCurrent subject: {$subject}" : '')
                        ."\n\nHTML body:\n".mb_substr($html, 0, 6000),
                ],
            ],
            temperature: 0.4,
            maxTokens: $action === 'subject' ? 200 : 900,
            options: $action === 'subject' ? ['json' => true] : [],
        ));

        if ($action === 'subject') {
            $decoded = json_decode($response->content, true);
            $subjects = collect(is_array($decoded) ? ($decoded['subjects'] ?? []) : [])
                ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                ->map(fn (string $item) => mb_substr(trim($item), 0, 120))
                ->take(5)
                ->values()
                ->all();

            if ($subjects === []) {
                throw new AiException('The AI returned no subject suggestions.');
            }

            return [
                'subjects' => $subjects,
                'subject' => $subjects[0],
            ];
        }

        return [
            'html' => $this->normalizeHtml($response->content),
        ];
    }

    protected function normalizeHtml(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:html)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);

        if ($content === '') {
            throw new AiException('The AI returned empty compose content.');
        }

        if (! str_contains($content, '<')) {
            $content = '<p>'.nl2br(e($content), false).'</p>';
        }

        $clean = EmailHtmlSanitizer::clean($content) ?? '';

        if (trim(strip_tags($clean)) === '') {
            throw new AiException('The AI returned empty compose content.');
        }

        return $clean;
    }
}
