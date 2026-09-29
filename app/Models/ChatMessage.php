<?php

namespace App\Models;

use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'kind',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class);
    }

    /**
     * @return array{id: int, conversation_id: int, body: string, created_at: string|null, user: array{id: int|null, name: string}}
     */
    public function toAppArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'kind' => $this->kind ?: 'text',
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => ($this->relationLoaded('attachments') ? $this->attachments : $this->attachments()->get())
                ->map(fn (ChatAttachment $attachment) => $attachment->toAppArray())
                ->values()
                ->all(),
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user?->name ?? 'Former member',
            ],
        ];
    }
}
