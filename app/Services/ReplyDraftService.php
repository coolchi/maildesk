<?php

namespace App\Services;

use App\Ai\AiManager;
use App\Ai\DTO\AiChatRequest;
use App\Ai\Exceptions\AiException;
use App\Models\Message;
use App\Models\Thread;
use App\Support\EmailHtmlSanitizer;

class ReplyDraftService
{
    public function __construct(
        public PlatformSettings $settings,
        public AiManager $ai,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->aiFeatureEnabled('reply_draft') && $this->ai->configured();
    }

    /**
     * Suggest an HTML reply body for a thread. Does not send mail.
     *
     * @throws AiException
     */
    public function draft(Thread $thread, ?string $tone = null): string
    {
        if (! $this->enabled()) {
            throw new AiException('Reply draft is disabled.');
        }

        $thread->loadMissing(['messages' => fn ($query) => $query->orderBy('created_at')]);

        $messages = $thread->messages;
        if ($messages->where('direction', 'inbound')->isEmpty()) {
            throw new AiException('This conversation has no customer message to reply to.');
        }

        $transcript = $messages
            ->take(-8)
            ->map(function (Message $message) {
                $body = trim((string) ($message->text_body ?: strip_tags((string) $message->html_body)));
                $body = mb_substr($body, 0, 1500);
                $who = $message->direction === 'inbound'
                    ? 'Customer ('.$message->from_email.')'
                    : 'Us';

                return "{$who}:\n".($body !== '' ? $body : '(empty)');
            })
            ->implode("\n\n");

        $tone = in_array($tone, ['friendly', 'formal', 'concise'], true) ? $tone : 'friendly';

        $response = $this->ai->driver()->chat(new AiChatRequest(
            messages: [
                [
                    'role' => 'system',
                    'content' => 'You draft professional email replies for a shared business inbox. '
                        .'Respond with HTML only using simple <p> tags (and optional <ul>/<li> or <br>). '
                        .'Do not include a subject line, greeting placeholders like [Name], signature, '
                        .'markdown fences, or commentary outside the HTML. '
                        .'Match the customer language when clear. Tone: '.$tone.'.',
                ],
                [
                    'role' => 'user',
                    'content' => "Subject: {$thread->subject}\n\nConversation:\n{$transcript}\n\n"
                        .'Draft a reply from us to the customer.',
                ],
            ],
            temperature: 0.4,
            maxTokens: 800,
        ));

        return $this->normalizeHtml($response->content);
    }

    protected function normalizeHtml(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:html)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);

        if ($content === '') {
            throw new AiException('The AI returned an empty reply draft.');
        }

        if (! str_contains($content, '<')) {
            $escaped = e($content);
            $content = '<p>'.nl2br($escaped, false).'</p>';
        }

        $clean = EmailHtmlSanitizer::clean($content) ?? '';

        if (trim(strip_tags($clean)) === '') {
            throw new AiException('The AI returned an empty reply draft.');
        }

        return $clean;
    }
}
