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
 *
 * Allowed MIME types and disks are configurable via `config('martis.attachments')`.
 * To allow additional file types, update `martis.attachments.allowed_mimes` in
 * your published config or set the MARTIS_ATTACHMENT_MIMES env variable.
 */
class AttachmentController extends MartisController
{
    /**
     * Upload a file attachment (image, document, etc.) for rich text fields.
     */
    public function upload(Request $request): JsonResponse
    {
        /** @var list<string> $allowedMimes */
        $allowedMimes = config('martis.attachments.allowed_mimes', [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'csv', 'zip', 'mp4', 'mp3',
        ]);

        /** @var int $maxSize */
        $maxSize = (int) config('martis.attachments.max_size', 10240);

        $mimeRule = ! empty($allowedMimes) ? 'mimes:'.implode(',', $allowedMimes) : null;
        $rules = array_filter([
            'required', 'file', 'max:'.$maxSize, $mimeRule,
        ]);

        $request->validate([
            'file' => $rules,
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        // Whitelist allowed storage disks to prevent writes to unexpected locations
        /** @var list<string> $allowedDisks */
        $allowedDisks = config('martis.attachments.allowed_disks', ['public', 'local']);
        $requestedDisk = $request->input('disk', 'public');
        $disk = in_array($requestedDisk, $allowedDisks, true) ? $requestedDisk : 'public';

        $filename = Str::random(40).'.'.$file->extension();
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
