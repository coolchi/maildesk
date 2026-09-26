<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Support\EmailHtmlSanitizer;

class BroadcastAssistService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('broadcast_assist') && $this->ai->configured();
    }

    /**
     * @return array{subjects: list<string>, html: string}
     *
     * @throws AiException
     */
    public function suggest(?string $brief, ?string $subject = null, ?string $html = null): array
    {
        if (! $this->enabled()) {
            throw new AiException('Broadcast assist is disabled.');
        }

        $brief = trim((string) $brief);
        $subject = trim((string) $subject);
        $html = trim((string) $html);
        $plain = trim(strip_tags($html));

        if ($brief === '' && $subject === '' && $plain === '') {
            throw new AiException('Add a brief, subject, or draft body first.');
        }

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You write marketing email campaign copy. '
                        .'Respond with JSON only: {"subjects":["...","...","..."],"html":"<p>...</p>"}. '
                        .'Subjects must be concise. HTML must use simple <p>/<h2>/<ul>/<li> tags only.',
                ],
                [
                    'role' => 'user',
                    'content' => "Broadcast assist request.\n"
                        .($brief !== '' ? "Brief: {$brief}\n" : '')
                        .($subject !== '' ? "Current subject: {$subject}\n" : '')
                        .($plain !== '' ? "Current HTML:\n".mb_substr($html, 0, 5000)."\n" : '')
                        .'Suggest subject-line variants and improved campaign HTML.',
                ],
            ],
            temperature: 0.5,
            maxTokens: 1200,
            options: ['json' => true],
        ));

        $decoded = json_decode($response->content, true);

        if (! is_array($decoded)) {
            throw new AiException('Broadcast assist response was not valid JSON.');
        }

        $subjects = collect($decoded['subjects'] ?? [])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => mb_substr(trim($item), 0, 120))
            ->take(5)
            ->values()
            ->all();

        $body = $this->normalizeHtml((string) ($decoded['html'] ?? ''));

        if ($subjects === []) {
            throw new AiException('The AI returned no subject variants.');
        }

        return [
            'subjects' => $subjects,
            'html' => $body,
        ];
    }

    protected function normalizeHtml(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:html)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);

        if ($content === '') {
            throw new AiException('The AI returned empty broadcast HTML.');
        }

        if (! str_contains($content, '<')) {
            $content = '<p>'.nl2br(e($content), false).'</p>';
        }

        $clean = EmailHtmlSanitizer::clean($content) ?? '';

        if (trim(strip_tags($clean)) === '') {
            throw new AiException('The AI returned empty broadcast HTML.');
        }

        return $clean;
    }
}
