<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'delivered_at' => $this->status === 'delivered'
                ? ($this->sent_at ?? $this->created_at)?->timezone(config('app.timezone'))->format('M j, g:i A')
                : null,
            'direction' => $this->direction,
            'log' => $this->direction === 'inbound' ? 'INBOUND' : 'POST /emails',
            'attachments' => [],
        ];

        if ($detailed) {
            $payload['html'] = $this->html_body;
            $payload['text'] = $this->text_body;
            $payload['cc'] = $this->cc;
            $payload['provider'] = $this->provider;
            $payload['provider_message_id'] = $this->provider_message_id;
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
