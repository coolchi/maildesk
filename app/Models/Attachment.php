<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = [
        'message_id',
        'filename',
        'content_type',
        'size',
        'disk',
        'path',
    ];

    /** Raster image types that are safe to render inline (no SVG: it can carry script). */
    public const PREVIEWABLE = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Wrap the stored file so it can be re-attached to a new outgoing email
     * (forwards and group fan-out). Returns null if the file is gone.
     */
    public function toUploadedFile(): ?UploadedFile
    {
        $disk = Storage::disk($this->disk ?: 'local');
        if (! $this->path || ! $disk->exists($this->path)) {
            return null;
        }

        return new UploadedFile($disk->path($this->path), $this->filename, $this->content_type, null, true);
    }

    public function isPreviewableImage(): bool
    {
        return in_array(strtolower((string) $this->content_type), self::PREVIEWABLE, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $image = $this->isPreviewableImage();

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'content_type' => $this->content_type ?: 'application/octet-stream',
            'extension' => strtolower(pathinfo($this->filename, PATHINFO_EXTENSION)),
            'size' => (int) $this->size,
            'size_label' => self::humanSize((int) $this->size),
            'is_image' => $image,
            'url' => route('attachments.download', $this->id),
            'preview_url' => $image ? route('attachments.download', ['attachment' => $this->id, 'inline' => 1]) : null,
        ];
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
