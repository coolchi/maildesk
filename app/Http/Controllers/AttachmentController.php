<?php

namespace App\Http\Controllers;

use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class AttachmentController extends Controller
{
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
