<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_read' => 'boolean',
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

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'snippet' => $this->snippet ?? '',
            'from' => $from,
            'unread' => ! $this->is_read,
            'label' => 'Inbox',
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->uuid,
                'from' => $message->from_email,
                'from_name' => $message->from_name,
                'html' => $message->html_body,
                'text' => $message->text_body,
                'sent' => ($message->sent_at ?? $message->received_at ?? $message->created_at)?->diffForHumans() ?? '',
                'direction' => $message->direction,
            ])->values()->all(),
            'message_count' => $this->message_count ?: $messages->count(),
            'updated' => $this->last_message_at?->diffForHumans()
                ?? $this->updated_at?->diffForHumans()
                ?? '',
        ];
    }
}
