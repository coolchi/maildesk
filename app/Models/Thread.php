<?php

namespace App\Models;

use App\Support\EmailHtmlSanitizer;
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
        'last_message_at',
        'message_count',
        'is_read',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_read' => 'boolean',
            'is_archived' => 'boolean',
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
        $from = $latest?->from_email
            ?? $messages->firstWhere('direction', 'inbound')?->from_email
            ?? 'unknown';

        $recipients = self::addresses($latest?->to);
        if ($recipients === [] && $this->mailbox_id) {
            $recipients = array_filter([$this->mailbox?->email]);
        }

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'snippet' => $this->snippet ?? '',
            'from' => $from,
            'to' => implode(', ', $recipients),
            'unread' => ! $this->is_read,
            'is_archived' => (bool) $this->is_archived,
            'label' => $this->is_archived ? 'Archive' : 'Inbox',
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->uuid,
                'from' => $message->from_email,
                'from_name' => $message->from_name,
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
