<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AutoReplyService
{
    public const DEFAULT_SUBJECT = 'Re: {{subject}}';

    public const DEFAULT_BODY = "Hi {{sender_name}},\n\nThanks for your email about \"{{subject}}\". We've received it and will get back to you soon.";

    public function __construct(
        protected EmailService $emails,
        protected AutomationService $automations,
    ) {}

    /**
     * @return array{enabled: bool, subject: string, body: string}
     */
    public function settings(Organization $organization): array
    {
        $stored = is_array($organization->settings['auto_reply'] ?? null)
            ? $organization->settings['auto_reply']
            : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'subject' => trim((string) ($stored['subject'] ?? '')) ?: self::DEFAULT_SUBJECT,
            'body' => trim((string) ($stored['body'] ?? '')) ?: self::DEFAULT_BODY,
        ];
    }

    /**
     * @param  array{enabled: bool, subject: string, body: string}  $input
     * @return array{enabled: bool, subject: string, body: string}
     */
    public function save(Organization $organization, array $input): array
    {
        $settings = $organization->settings ?? [];
        $settings['auto_reply'] = [
            'enabled' => (bool) $input['enabled'],
            'subject' => trim($input['subject']),
            'body' => trim($input['body']),
        ];

        $organization->forceFill(['settings' => $settings])->save();

        return $this->settings($organization->fresh() ?? $organization);
    }

    /**
     * Send the workspace acknowledgment for a newly received message.
     * One reply per conversation. Automated mail, spam, and our own addresses are skipped.
     */
    public function acknowledge(Message $inbound): ?Message
    {
        if ($inbound->direction !== 'inbound') {
            return null;
        }

        $inbound->loadMissing(['organization', 'mailbox', 'thread']);
        $organization = $inbound->organization;
        $thread = $inbound->thread;

        if ($organization === null || $thread === null) {
            return null;
        }

        $settings = $this->settings($organization);
        if (! $settings['enabled']) {
            return null;
        }

        if ($thread->is_spam || $thread->is_trashed) {
            return null;
        }

        $recipient = $this->recipient($inbound);
        if ($recipient === null || $this->shouldSkipAddress($organization, $recipient) || $this->isAutomated($inbound)) {
            return null;
        }

        if ($this->alreadyReplied($thread) || $this->repliedToAddressRecently($organization, $recipient)) {
            return null;
        }

        $from = $this->fromAddress($organization, $inbound);
        $subject = $this->render($settings['subject'], $inbound);
        $text = $this->render($settings['body'], $inbound);
        $html = '<p>'.nl2br(e($text), false).'</p>';

        $references = array_values(array_unique(array_filter([
            ...($inbound->references ?? []),
            $inbound->in_reply_to,
            $inbound->message_id_header,
        ])));

        $snapshot = [
            'is_read' => $thread->is_read,
            'snippet' => $thread->snippet,
        ];

        try {
            $sent = $this->emails->send($organization, [
                'from' => $from['name'] !== '' ? "{$from['name']} <{$from['email']}>" : $from['email'],
                'to' => [$recipient],
                'subject' => $subject,
                'html' => $html,
                'text' => $text,
                'headers' => array_filter([
                    'Auto-Submitted' => 'auto-replied',
                    'X-Auto-Response-Suppress' => 'All',
                    'Precedence' => 'auto_reply',
                    'In-Reply-To' => $inbound->message_id_header,
                    'References' => $references !== [] ? implode(' ', $references) : null,
                ]),
                'in_reply_to' => $inbound->message_id_header,
                'references' => $references ?: null,
                'thread' => true,
                'signature' => false,
                'expand_groups' => false,
                'tags' => ['auto-reply'],
                'meta' => [
                    'auto_reply' => true,
                    'auto_reply_for' => $inbound->id,
                ],
            ], thread: $thread);
        } catch (Throwable $e) {
            Log::info('Auto-reply was not sent', [
                'message_id' => $inbound->id,
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $thread->forceFill($snapshot)->save();

        return $sent;
    }

    /**
     * @return array{email: string, name: string}
     */
    private function fromAddress(Organization $organization, Message $inbound): array
    {
        $mailbox = $inbound->mailbox;

        if ($mailbox === null) {
            $candidates = collect($inbound->to ?? [])
                ->map(fn ($item) => Str::lower(is_array($item) ? (string) ($item['email'] ?? '') : (string) $item))
                ->filter()
                ->values()
                ->all();

            if ($candidates !== []) {
                $mailbox = Mailbox::query()
                    ->where('organization_id', $organization->id)
                    ->where('status', 'active')
                    ->whereIn(DB::raw('lower(email)'), $candidates)
                    ->first();
            }
        }

        $email = $mailbox?->email ?: $this->automations->resolveFromAddress($organization);

        return [
            'email' => $email,
            'name' => trim((string) ($mailbox?->display_name ?: $organization->name)),
        ];
    }

    private function recipient(Message $inbound): ?string
    {
        $replyTo = collect($inbound->reply_to ?? [])
            ->map(fn ($item) => is_array($item) ? (string) ($item['email'] ?? '') : (string) $item)
            ->map(fn (string $email) => Str::lower(trim($email)))
            ->first(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL));

        $from = Str::lower(trim((string) $inbound->from_email));
        $email = $replyTo ?: $from;

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function shouldSkipAddress(Organization $organization, string $email): bool
    {
        $local = Str::before(Str::before($email, '@'), '+');
        if (in_array($local, [
            'mailer-daemon',
            'postmaster',
            'noreply',
            'no-reply',
            'donotreply',
            'do-not-reply',
            'bounce',
            'bounces',
        ], true)) {
            return true;
        }

        return Mailbox::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();
    }

    private function isAutomated(Message $inbound): bool
    {
        $autoSubmitted = Str::lower(trim((string) $this->header($inbound, 'Auto-Submitted')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        $precedence = Str::lower(trim((string) $this->header($inbound, 'Precedence')));
        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }

        foreach (['List-Id', 'List-Unsubscribe', 'X-Autoreply', 'X-Autorespond', 'X-Auto-Response-Suppress'] as $name) {
            if ($this->header($inbound, $name) !== null) {
                return true;
            }
        }

        return false;
    }

    private function header(Message $message, string $name): ?string
    {
        foreach ($message->headers ?? [] as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', $value);
            }

            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private function alreadyReplied(Thread $thread): bool
    {
        return Message::query()
            ->where('thread_id', $thread->id)
            ->where('direction', 'outbound')
            ->whereJsonContains('tags', 'auto-reply')
            ->exists();
    }

    private function repliedToAddressRecently(Organization $organization, string $email): bool
    {
        return Message::query()
            ->where('organization_id', $organization->id)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subHour())
            ->whereJsonContains('tags', 'auto-reply')
            ->whereJsonContains('to', $email)
            ->exists();
    }

    private function render(string $template, Message $inbound): string
    {
        $name = trim((string) $inbound->from_name);
        $subject = trim((string) ($inbound->subject ?: '(no subject)'));

        return trim(strtr($template, [
            '{{subject}}' => $subject,
            '{{sender_name}}' => $name !== '' ? $name : 'there',
            '{{sender_email}}' => (string) $inbound->from_email,
        ]));
    }
}
