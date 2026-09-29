<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ChatAttachment extends Model
{
    protected $fillable = [
        'chat_message_id',
        'filename',
        'content_type',
        'size',
        'duration_ms',
        'disk',
        'path',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower((string) $this->content_type), 'image/')
            && ! str_contains(strtolower((string) $this->content_type), 'svg');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAppArray(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'content_type' => $this->content_type,
            'size' => (int) $this->size,
            'size_label' => Attachment::humanSize((int) $this->size),
            'duration_ms' => $this->duration_ms,
            'is_image' => $this->isImage(),
            'url' => url('/api/app/chat/attachments/'.$this->id),
        ];
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk ?: 'local')->exists($this->path);
    }
}
