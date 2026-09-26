<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Download (or, for raster images with ?inline=1, preview) a stored
     * attachment. Scoped to the current workspace.
     */
    public function download(Request $request, int $attachment): StreamedResponse
    {
        $organization = CurrentOrganization::from($request);

        /** @var Attachment $model */
        $model = Attachment::query()
            ->whereHas('message', fn ($q) => $q->where('organization_id', $organization->id))
            ->findOrFail($attachment);

        $disk = Storage::disk($model->disk ?: 'local');
        abort_unless($disk->exists($model->path), 404, 'This attachment file is missing from storage.');

        $inline = $request->boolean('inline') && $model->isPreviewableImage();
        $headers = [
            'Content-Type' => $inline ? $model->content_type : ($model->content_type ?: 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        return $inline
            ? $disk->response($model->path, $model->filename, $headers, 'inline')
            : $disk->download($model->path, $model->filename, $headers);
    }

    public function store(Request $request): JsonResponse
    {
        CurrentOrganization::from($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $organization = CurrentOrganization::from($request);
        $path = $file->store('attachments/tmp/'.$organization->id, 'local');

        return response()->json([
            'id' => Str::uuid()->toString(),
            'filename' => $file->getClientOriginalName(),
            'content_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'path' => $path,
            'disk' => 'local',
        ]);
    }
}
