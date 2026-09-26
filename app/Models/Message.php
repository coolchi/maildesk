<?php

namespace App\Models;

use App\Support\EmailHtmlSanitizer;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'organization_id',
        'thread_id',
        'mailbox_id',
        'direction',
        'status',
        'provider',
        'provider_message_id',
        'message_id_header',
        'in_reply_to',
        'references',
        'from_email',
        'from_name',
        'to',
        'cc',
        'bcc',
        'reply_to',
        'subject',
        'text_body',
        'html_body',
        'headers',
        'tags',
        'meta',
        'sent_at',
        'scheduled_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'reply_to' => 'array',
            'references' => 'array',
            'headers' => 'array',
            'tags' => 'array',
            'meta' => 'array',
            'sent_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            if (empty($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSentArray(): array
    {
        $to = is_array($this->to) ? array_values(array_filter($this->to)) : array_filter([(string) $this->to]);
        $at = $this->sent_at ?? $this->created_at;
        $failed = in_array($this->status, ['failed', 'bounced', 'complained', 'suppressed'], true);

        return [
            'id' => $this->uuid,
            'subject' => $this->subject ?: '(no subject)',
            'to' => $this->firstAddress($this->to),
            'to_count' => count($to),
            'from' => $this->from_email,
            'status' => $this->status,
            'failed' => $failed,
            'error' => $failed
                ? (data_get($this->meta, 'bounce.message') ?? data_get($this->meta, 'error'))
                : null,
            'thread_id' => $this->thread_id,
            'sent_at' => $at?->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            'sent_at_iso' => $at?->toIso8601String(),
            'sent_ago' => $at?->diffForHumans() ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(bool $detailed = false): array
    {
        $to = $this->firstAddress($this->to);

        $payload = [
            'id' => $this->uuid,
            'to' => $to,
            'from' => $this->from_email,
            'from_name' => $this->from_name,
            'status' => $this->status,
            'subject' => $this->subject,
            'sent' => ($this->sent_at ?? $this->created_at)?->diffForHumans() ?? '',
            'sent_at' => ($this->sent_at ?? $this->created_at)?->timezone(config('app.timezone'))->format('M j, g:i A'),
            'delivered_at' => isset($this->meta['delivered_at'])
                ? Carbon::parse($this->meta['delivered_at'])->timezone(config('app.timezone'))->format('M j, g:i A')
                : ($this->status === 'delivered'
                    ? ($this->sent_at ?? $this->created_at)?->timezone(config('app.timezone'))->format('M j, g:i A')
                    : null),
            'open_count' => (int) ($this->meta['open_count'] ?? 0),
            'click_count' => (int) ($this->meta['click_count'] ?? 0),
            'direction' => $this->direction,
            'log' => $this->direction === 'inbound' ? 'INBOUND' : 'POST /emails',
            'attachments' => [],
        ];

        if ($detailed) {
            $payload['html'] = EmailHtmlSanitizer::clean($this->html_body);
            $payload['html_source'] = $this->html_body;
            $payload['text'] = $this->text_body;
            $payload['cc'] = $this->cc;
            $payload['provider'] = $this->provider;
            $payload['provider_message_id'] = $this->provider_message_id;
            $payload['events'] = $this->meta['events'] ?? [];
            $payload['bounce'] = $this->meta['bounce'] ?? null;
            $payload['first_opened_at'] = isset($this->meta['first_opened_at'])
                ? Carbon::parse($this->meta['first_opened_at'])->timezone(config('app.timezone'))->format('M j, g:i A')
                : null;
            $payload['last_opened_at'] = isset($this->meta['last_opened_at'])
                ? Carbon::parse($this->meta['last_opened_at'])->timezone(config('app.timezone'))->format('M j, g:i A')
                : null;
            $payload['first_clicked_at'] = isset($this->meta['first_clicked_at'])
                ? Carbon::parse($this->meta['first_clicked_at'])->timezone(config('app.timezone'))->format('M j, g:i A')
                : null;
            $payload['last_clicked_at'] = isset($this->meta['last_clicked_at'])
                ? Carbon::parse($this->meta['last_clicked_at'])->timezone(config('app.timezone'))->format('M j, g:i A')
                : null;
            $payload['attachments'] = $this->attachments()->get()
                ->map(fn (Attachment $attachment) => $attachment->toWorkspaceArray())
                ->values()
                ->all();
        }

        return $payload;
    }

    private function firstAddress(mixed $addresses): string
    {
        if (is_string($addresses)) {
            return $addresses;
        }

        if (! is_array($addresses) || $addresses === []) {
            return '';
        }

        $first = $addresses[0];

        if (is_string($first)) {
            return $first;
        }

        if (is_array($first)) {
            return (string) ($first['email'] ?? $first['address'] ?? '');
        }

        return '';
    }
}
