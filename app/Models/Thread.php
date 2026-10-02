<?php

namespace App\Models;

use App\Support\EmailHtmlSanitizer;
use App\Support\EmailSnippet;
use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'mailbox_id',
        'subject',
        'snippet',
        'ai',
        'last_message_at',
        'message_count',
        'is_read',
        'is_archived',
        'is_spam',
        'is_trashed',
        'trashed_at',
    ];

    protected function casts(): array
    {
        return [
            'ai' => 'array',
            'last_message_at' => 'datetime',
            'is_read' => 'boolean',
            'is_archived' => 'boolean',
            'is_spam' => 'boolean',
            'is_trashed' => 'boolean',
            'trashed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $messages = $this->relationLoaded('messages')
            ? $this->messages
            : $this->messages()->orderBy('created_at')->get();

        $latest = $messages->last();
        $fromMessage = $latest
            ?? $messages->firstWhere('direction', 'inbound');
        $fromEmail = $fromMessage?->from_email
            ?? 'unknown';
        $fromName = $fromMessage?->senderName();

        $recipients = self::addresses($latest?->to);
        if ($recipients === [] && $this->mailbox_id) {
            $recipients = array_filter([$this->mailbox?->email]);
        }

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'snippet' => EmailSnippet::display($this->snippet),
            'ai' => $this->aiWorkspacePayload(),
            'from' => $fromEmail,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to' => implode(', ', $recipients),
            'unread' => ! $this->is_read,
            'is_archived' => (bool) $this->is_archived,
            'is_spam' => (bool) $this->is_spam,
            'is_trashed' => (bool) $this->is_trashed,
            'trashed_at' => $this->trashed_at?->toIso8601String(),
            'label' => match (true) {
                (bool) $this->is_trashed => 'Trash',
                (bool) $this->is_spam => 'Spam',
                (bool) $this->is_archived => 'Archive',
                default => 'Inbox',
            },
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->uuid,
                'from' => $message->from_email,
                'from_name' => $message->senderName(),
                'to' => implode(', ', self::addresses($message->to)),
                'html' => EmailHtmlSanitizer::clean($message->html_body),
                'text' => $message->text_body,
                'sent' => ($message->sent_at ?? $message->received_at ?? $message->created_at)?->diffForHumans() ?? '',
                'direction' => $message->direction,
                'cc' => implode(', ', self::addresses($message->cc)),
                'bcc' => $message->direction === 'outbound' ? implode(', ', self::addresses($message->bcc)) : '',
                'status' => $message->status,
                'error' => in_array($message->status, ['failed', 'suppressed', 'bounced'], true)
                    ? (data_get($message->meta, 'bounce.reason') ?? data_get($message->meta, 'error'))
                    : null,
                'can_retry' => $message->direction === 'outbound' && $message->status === 'failed',
                'fanout' => data_get($message->meta, 'group_fanout') ?: null,
                'forwarded' => data_get($message->meta, 'forwarded_from') !== null,
                'attachments' => ($message->relationLoaded('attachments') ? $message->attachments : $message->attachments()->get())
                    ->map(fn (Attachment $attachment) => $attachment->toWorkspaceArray())
                    ->values()
                    ->all(),
            ])->values()->all(),
            'message_count' => $this->message_count ?: $messages->count(),
            'updated' => $this->last_message_at?->diffForHumans()
                ?? $this->updated_at?->diffForHumans()
                ?? '',
        ];
    }

    /**
     * @return array{priority: ?string, intent: ?string, language: ?string, summary: ?string, action_items: list<string>}|null
     */
    protected function aiWorkspacePayload(): ?array
    {
        $ai = $this->ai;

        if (! is_array($ai) || $ai === []) {
            return null;
        }

        $priority = isset($ai['priority']) && is_string($ai['priority']) ? $ai['priority'] : null;
        $intent = isset($ai['intent']) && is_string($ai['intent']) ? $ai['intent'] : null;
        $language = isset($ai['language']) && is_string($ai['language']) ? $ai['language'] : null;
        $summary = isset($ai['summary']) && is_string($ai['summary']) ? $ai['summary'] : null;
        $actionItems = collect($ai['action_items'] ?? [])
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->values()
            ->all();

        if ($priority === null && $intent === null && $language === null && $summary === null && $actionItems === []) {
            return null;
        }

        $payload = [
            'priority' => $priority,
            'intent' => $intent,
            'language' => $language,
        ];

        if ($summary !== null) {
            $payload['summary'] = $summary;
        }

        if ($actionItems !== []) {
            $payload['action_items'] = $actionItems;
        }

        return $payload;
    }

    /**
     * Normalizes stored recipients (plain strings or {email, name} arrays).
     *
     * @return list<string>
     */
    private static function addresses(mixed $to): array
    {
        return collect(is_array($to) ? $to : array_filter([$to]))
            ->map(fn ($item) => is_array($item) ? ($item['email'] ?? $item['address'] ?? null) : $item)
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->values()
            ->all();
    }
}
