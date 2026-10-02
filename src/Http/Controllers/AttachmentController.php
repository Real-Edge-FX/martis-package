<?php

declare(strict_types=1);

namespace Martis\Http\Controllers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Martis\Fields\Markdown;
use Martis\Fields\Trix;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * Handles file uploads for Trix and Markdown editors.
 *
 * An upload names the field it belongs to, as the form it comes from does:
 * the `resource` (URI key), the `field` (attribute) and, on an edit form, the
 * `id` of the record (the `repeater` and `repeatable` of the row, for a field
 * inside a Repeater). The request is authorised like that form: the user may
 * list the resource, may update the record `id` names or, without one, may
 * create the resource, and the field is on that form, the user can see it and
 * it declares `withFiles()`. The file is stored on the disk the field
 * declares (`withFiles($disk)`, the panel's `martis.storage.disk` without
 * one), never on one the request names, under `martis-attachments/`, and the
 * public URL comes back for embedding in the editor content.
 *
 * Allowed extensions and the maximum size are configurable via
 * `config('martis.attachments')`. To allow additional file types, update
 * `martis.attachments.allowed_mimes` in your published config or set the
 * MARTIS_ATTACHMENT_MIMES env variable. The route carries a dedicated
 * per-user throttle (see `RouteMiddleware::attachmentUploadThrottle()`), and
 * `php artisan martis:attachments:prune` removes the files no record holds.
 */
class AttachmentController extends MartisController
{
    /** Create the controller and inject the resource registry. */
    public function __construct(
        private readonly ResourceRegistry $registry,
    ) {}

    /**
     * Upload a file attachment (image, document, etc.) for a rich text field.
     */
    public function upload(Request $request): JsonResponse
    {
        // The upload names the field it belongs to; the file is validated
        // after the user is authorised, so an unauthorised caller learns
        // nothing about the rules.
        $request->validate([
            'resource' => ['required', 'string'],
            'field' => ['required', 'string'],
        ]);

        $resource = (string) $request->input('resource');
        $attribute = (string) $request->input('field');

        [$resourceClass, $error] = $this->resolveResourceClass($this->registry, $resource);
        if ($error !== null) {
            return $error;
        }

        // The entry gate of the resource, then the ability to write the form
        // the field is on: update on the record `id` names, otherwise create.
        // An `id` the user may not update falls to the create form, so it
        // answers like a missing one (see resolveFormFromRecordId()).
        /** @var class-string<\Martis\Resource> $resourceClass */
        if ($forbidden = $this->forbiddenUnlessAuthorizedToViewAny($request, $resourceClass)) {
            return $forbidden;
        }

        $rawId = $request->input('id');
        [$formInstance, $formContext] = $this->resolveFormFromRecordId($request, $resourceClass, is_string($rawId) || is_int($rawId) ? $rawId : null);

        if ($formContext === 'create' && ! $formInstance->authorizedToCreate($request)) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

        // A rich text field of that form (a hidden one is not found), in the
        // row a Repeater names when the upload comes from one.
        $field = $this->findFormField($formInstance, $request, $formContext, $attribute, [Trix::class, Markdown::class], repeaterRow: $this->repeaterRowOf($request));
        if (! $field instanceof Trix && ! $field instanceof Markdown) {
            return JsonErrorResponse::notFound("Field '{$attribute}' not found.")->toResponse();
        }

        // The field must accept files, and the disk is the one it declares:
        // the request never chooses where a file is written.
        $disk = $field->getWithFilesDisk();
        if ($disk === null) {
            return JsonErrorResponse::forbidden('This action is unauthorized.')->toResponse();
        }

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
