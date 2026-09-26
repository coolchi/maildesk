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
    public const PREVIEWABLE_IMAGES = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'];

    /** Non-image types safe to open inline in a sandboxed preview. */
    public const PREVIEWABLE_DOCUMENTS = ['application/pdf'];

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
        return in_array(strtolower((string) $this->content_type), self::PREVIEWABLE_IMAGES, true);
    }

    public function isPreviewable(): bool
    {
        $type = strtolower((string) $this->content_type);

        return $this->isPreviewableImage()
            || in_array($type, self::PREVIEWABLE_DOCUMENTS, true);
    }

    /**
     * @return 'image'|'pdf'|null
     */
    public function previewKind(): ?string
    {
        if ($this->isPreviewableImage()) {
            return 'image';
        }

        if (strtolower((string) $this->content_type) === 'application/pdf') {
            return 'pdf';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $image = $this->isPreviewableImage();
        $previewable = $this->isPreviewable();

        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'content_type' => $this->content_type ?: 'application/octet-stream',
            'extension' => strtolower(pathinfo($this->filename, PATHINFO_EXTENSION)),
            'size' => (int) $this->size,
            'size_label' => self::humanSize((int) $this->size),
            'is_image' => $image,
            'previewable' => $previewable,
            'preview_kind' => $this->previewKind(),
            'url' => route('attachments.download', $this->id),
            'preview_url' => $previewable
                ? route('attachments.download', ['attachment' => $this->id, 'inline' => 1])
                : null,
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
