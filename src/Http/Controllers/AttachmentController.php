<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Handles file uploads for Trix and Markdown editors.
 *
 * Files are stored on the configured disk under `martis-attachments/`.
 * Returns the public URL for embedding in the editor content.
 */
class AttachmentController extends MartisController
{
    /**
     * Upload a file attachment (image, document, etc.) for rich text fields.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $disk = $request->input('disk', 'public');

        $filename = Str::random(40).'.'.$file->getClientOriginalExtension();
        $path = (string) $file->storeAs('martis-attachments', $filename, $disk);

        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        $url = $storage->url($path);

        return response()->json([
            'url' => $url,
            'href' => $url,
        ]);
    }
}
