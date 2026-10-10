<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Martis\Rules\NoActiveContent;

/**
 * File upload field.
 *
 * Stores any uploaded file on a configurable disk.
 * Resolves to an array with {path, url, name, originalName} for frontend rendering.
 *
 * Usage:
 *   File::make('attachment')
 *       ->disk('s3')
 *       ->storagePath('uploads/docs')
 *       ->maxSize(10240)   // 10MB in KB
 *       ->acceptedTypes(['pdf', 'doc', 'docx'])   // a security control, see below
 *       ->preserveOriginalName()
 *       ->sanitizeFileName()
 *       ->nullable()
 *
 *   File::make('documents')
 *       ->multiple()
 *       ->disk('public')
 *       ->storagePath('uploads/docs')
 *       ->maxSize(5120)
 *
 * Active content (HTML, SVG, XML, script files) is refused unless the
 * developer opts in: a file the web server serves from the application's own
 * origin runs in it when a user opens the link. `acceptedTypes()` is
 * therefore a security control, not a convenience: list the types the field
 * is for. Listing an active type (`'svg'`, `'html'`) or calling
 * `allowActiveContent()` is the opt-in. See {@see NoActiveContent}.
 */
class File extends Field
{
    protected string $disk = 'public';

    protected string $storagePath = 'uploads';

    protected ?int $maxSize = null; // KB

    /** @var list<string> */
    protected array $acceptedTypes = [];

    protected bool $multiple = false;

    /**
     * When true, the field accepts HTML, SVG, XML and script files whatever
     * `acceptedTypes()` lists (see `allowActiveContent()`).
     */
    protected bool $allowActiveContent = false;

    /**
     * When true, store files with their original name instead of a random hash.
     */
    protected bool $preserveOriginalName = false;

    /**
     * When true, sanitize filenames (replace spaces/special chars with underscores).
     */
    protected bool $sanitizeFileNames = false;

    /**
     * Custom filename sanitizer callable.
     * Receives (string $filename) and must return string.
     *
     * @var callable|null
     */
    protected mixed $fileNameSanitizer = null;

    /**
     * When false, frontend hides file info (max size, accepted types).
     */
    protected bool $showFileInfo = true;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'file';
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Set the storage disk (default: 'public').
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Get disk.
     */
    public function getDisk(): string
    {
        return $this->disk;
    }

    /**
     * Set the directory within the disk where uploads are stored.
     */
    public function storagePath(string $path): static
    {
        $this->storagePath = $path;

        return $this;
    }

    /**
     * Set maximum file size in kilobytes.
     */
    public function maxSize(int $kb): static
    {
        $this->maxSize = $kb;

        return $this;
    }

    /**
     * Restrict accepted file MIME extensions (e.g. ['pdf', 'png', 'jpg']).
     *
     * This is a security control: without it the field takes any file that
     * is not active content (see `allowActiveContent()`). List the types the
     * field is for. Listing an active type (`'svg'`, `'html'`, `'xml'`)
     * accepts it: the type is an explicit opt-in.
     *
     * @param  list<string>  $mimes
     */
    public function acceptedTypes(array $mimes): static
    {
        $this->acceptedTypes = $mimes;

        return $this;
    }

    /**
     * Accept active content: HTML, SVG, XML and script files, which a browser
     * runs when they are opened.
     *
     * A file the web server serves from the application's origin (the
     * `public` disk, the default) runs there with the session of whoever
     * opens it, so the field refuses such files by default (a 422). Opt in
     * only when the uploads are never served from that origin: a private disk
     * behind a download route, another domain, or a server rule that sends
     * `Content-Disposition: attachment` and `Content-Security-Policy: sandbox`.
     * Listing the type in `acceptedTypes()` opts in for that type alone.
     */
    public function allowActiveContent(bool $value = true): static
    {
        $this->allowActiveContent = $value;

        return $this;
    }

    /**
     * Whether the field accepts active content whatever `acceptedTypes()` lists.
     */
    public function allowsActiveContent(): bool
    {
        return $this->allowActiveContent;
    }

    /**
     * Enable multiple file uploads.
     *
     * When enabled, the model attribute stores a JSON array of paths.
     * The field resolves to an array of {path, url, name} objects.
     */
    public function multiple(bool $value = true): static
    {
        $this->multiple = $value;

        return $this;
    }

    /**
     * Is multiple.
     */
    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Preserve the original filename when storing uploaded files.
     * A unique suffix is appended to avoid collisions.
     */
    public function preserveOriginalName(bool $value = true): static
    {
        $this->preserveOriginalName = $value;

        return $this;
    }

    /**
     * Enable filename sanitization: replaces spaces and special characters
     * with underscores, lowercases the name.
     *
     * Optionally accepts a callable for custom sanitization:
     *   ->sanitizeFileName(fn(string $name) => preg_replace('/[^a-z0-9._-]/', '_', strtolower($name)))
     *
     * @param  bool|callable  $sanitizer  true for default, or a custom callable(string): string
     */
    public function sanitizeFileName(bool|callable $sanitizer = true): static
    {
        if (is_callable($sanitizer)) {
            $this->sanitizeFileNames = true;
            $this->fileNameSanitizer = $sanitizer;
        } else {
            $this->sanitizeFileNames = $sanitizer;
        }

        return $this;
    }

    /**
     * Show or hide the file info (max size, accepted types) below the field.
     */
    public function showFileInfo(bool $value = true): static
    {
        $this->showFileInfo = $value;

        return $this;
    }

    /**
     * Hide file info display below the field.
     */
    public function hideFileInfo(): static
    {
        $this->showFileInfo = false;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Filename handling
    // -------------------------------------------------------------------------

    /**
     * The name an upload is stored under, once it is checked.
     *
     * Throws a validation error for active content the field does not
     * accept, so a caller that writes a file without validating it first
     * never stores one (see `NoActiveContent`). A subclass that changes the
     * naming overrides `generateStorageFilename()`, and is checked too.
     *
     * @throws ValidationException
     */
    protected function storageFilename(UploadedFile $file): string
    {
        $filename = $this->generateStorageFilename($file);

        if (! $this->allowActiveContent && NoActiveContent::refuses($file, $this->acceptedTypes, $filename)) {
            throw ValidationException::withMessages([$this->attribute => [NoActiveContent::message($this->label)]]);
        }

        return $filename;
    }

    /**
     * Generate the storage filename for an uploaded file.
     */
    protected function generateStorageFilename(UploadedFile $file): string
    {
        $originalName = $file->getClientOriginalName();

        if (! $this->preserveOriginalName) {
            // Default Laravel behavior: random hash
            return $file->hashName();
        }

        // Sanitize if enabled
        if ($this->sanitizeFileNames) {
            $originalName = $this->sanitizeFilenameValue($originalName);
        }

        // Add unique suffix to avoid collisions
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $suffix = '_'.Str::lower(Str::random(6));

        return $name.$suffix.($ext ? '.'.$ext : '');
    }

    /**
     * Apply sanitization to a filename.
     */
    protected function sanitizeFilenameValue(string $filename): string
    {
        if ($this->fileNameSanitizer !== null) {
            return ($this->fileNameSanitizer)($filename);
        }

        // Default sanitizer: lowercase, replace spaces and special chars with underscore
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $name = Str::lower($name);
        $name = (string) preg_replace('/[^a-z0-9._-]/', '_', $name);
        $name = (string) preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');

        return $name.($ext ? '.'.Str::lower($ext) : '');
    }

    /**
     * Get the original filename for display purposes.
     */
    protected function getDisplayName(UploadedFile $file): string
    {
        return $file->getClientOriginalName();
    }

    // -------------------------------------------------------------------------
    // Value lifecycle
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->isReadonly()) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        // A computed field has no backing attribute to write (see Field::fill()).
        if ($this->computed) {
            return;
        }

        if ($this->multiple) {
            $this->fillMultiple($model, $value);

            return;
        }

        if ($value instanceof UploadedFile) {
            // The name first: it refuses active content before the stored
            // file it replaces is deleted.
            $filename = $this->storageFilename($value);
            $this->deleteStoredFile($model);
            $path = $value->storeAs($this->storagePath, $filename, $this->disk);
            $model->setAttribute($this->attribute, $path ?: null);

            return;
        }

        if ($value === null || $value === '') {
            $this->deleteStoredFile($model);
            $model->setAttribute($this->attribute, null);

            return;
        }

        // String passthrough -- keep existing path value
        $model->setAttribute($this->attribute, $value);
    }

    /**
     * Fill for multiple mode.
     */
    protected function fillMultiple(Model $model, mixed $value): void
    {
        $existingPaths = $this->getExistingPaths($model);

        if (! is_array($value) || $value === []) {
            foreach ($existingPaths as $path) {
                $this->deletePathFromDisk($path);
            }
            $model->setAttribute($this->attribute, $this->storableStructuredValue($model, $this->attribute, []));

            return;
        }

        /** @var array<mixed> $rawFiles */
        $rawFiles = $value['files'] ?? [];
        /** @var array<mixed> $rawExisting */
        $rawExisting = $value['existing'] ?? [];

        // Name every upload before anything is deleted or stored: an upload
        // the field refuses (active content) must leave the record as it was.
        $filenames = [];
        foreach ($rawFiles as $index => $file) {
            if ($file instanceof UploadedFile) {
                $filenames[$index] = $this->storageFilename($file);
            }
        }

        // Only honour "existing" paths the model actually owns. The list is
        // client-supplied, so without this guard a caller could inject
        // arbitrary disk paths (another record's uploads, a traversal) into
        // the stored set. Any owned path omitted here is treated as a
        // deletion below — so an injected value must never widen the set.
        /** @var list<string> $keepPaths */
        $keepPaths = [];
        foreach ($rawExisting as $p) {
            if (is_string($p) && $p !== '' && in_array($p, $existingPaths, true)) {
                $keepPaths[] = $p;
            }
        }

        // Delete files that are no longer kept
        $removedPaths = array_diff($existingPaths, $keepPaths);
        foreach ($removedPaths as $path) {
            $this->deletePathFromDisk($path);
        }

        // Store new uploads
        $newPaths = [];
        foreach ($rawFiles as $index => $file) {
            if ($file instanceof UploadedFile) {
                $path = $file->storeAs($this->storagePath, $filenames[$index], $this->disk);
                if ($path) {
                    $newPaths[] = $path;
                }
            }
        }

        $allPaths = array_merge($keepPaths, $newPaths);
        $model->setAttribute($this->attribute, $this->storableStructuredValue($model, $this->attribute, $allPaths));
    }

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($this->resolveAttribute($model, $attr), $model, $attr, $this->safeRequest());
        }

        if ($this->multiple) {
            return $this->resolveMultiple($model, $attr);
        }

        $path = $this->resolveAttribute($model, $attr);

        if ($path === null || $path === '') {
            return null;
        }

        /** @var Cloud $disk */
        $disk = Storage::disk($this->disk);

        return [
            'path' => $path,
            'url' => $disk->url($path),
            'name' => $this->resolveDisplayName($path),
        ];
    }

    /**
     * Resolve for multiple mode.
     *
     * @return list<array{path: string, url: string, name: string}>
     */
    protected function resolveMultiple(Model $model, string $attr): array
    {
        $paths = $this->getExistingPathsFromRaw($this->resolveAttribute($model, $attr));

        if (empty($paths)) {
            return [];
        }

        /** @var Cloud $disk */
        $disk = Storage::disk($this->disk);

        return array_map(fn (string $path): array => [
            'path' => $path,
            'url' => $disk->url($path),
            'name' => $this->resolveDisplayName($path),
        ], $paths);
    }

    /**
     * Get a human-friendly display name from a stored path.
     *
     * If preserveOriginalName is on, the stored filename is meaningful.
     * Otherwise, show the basename (hash name).
     */
    protected function resolveDisplayName(string $path): string
    {
        $basename = basename($path);

        if ($this->preserveOriginalName) {
            // Remove the _xxxxxx suffix we added for uniqueness
            $name = pathinfo($basename, PATHINFO_FILENAME);
            $ext = pathinfo($basename, PATHINFO_EXTENSION);
            // Remove last _xxxxxx (6 random chars) if present
            $cleanName = (string) preg_replace('/_[a-z0-9]{6}$/', '', $name);

            return $cleanName.($ext ? '.'.$ext : '');
        }

        return $basename;
    }

    /**
     * Delete the currently stored file(s) from disk (does NOT update the model attribute).
     */
    public function deleteStoredFile(Model $model): void
    {
        if ($this->multiple) {
            $paths = $this->getExistingPaths($model);
            foreach ($paths as $path) {
                $this->deletePathFromDisk($path);
            }

            return;
        }

        $path = $model->getAttribute($this->attribute);

        if ($path !== null && $path !== '') {
            Storage::disk($this->disk)->delete($path);
        }
    }

    /**
     * Delete a single path from disk.
     */
    protected function deletePathFromDisk(string $path): void
    {
        if ($path !== '') {
            Storage::disk($this->disk)->delete($path);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    protected function getExistingPaths(Model $model): array
    {
        return $this->getExistingPathsFromRaw($model->getAttribute($this->attribute));
    }

    /**
     * @return list<string>
     */
    protected function getExistingPathsFromRaw(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return array_values(array_filter($raw, fn ($p): bool => is_string($p) && $p !== ''));
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded)
                ? array_values(array_filter($decoded, fn ($p): bool => is_string($p) && $p !== ''))
                : [];
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function buildRules(?string $context = null): array
    {
        if ($this->multiple) {
            $rules = [];

            if ($this->required) {
                $rules[] = 'required';
            } elseif ($this->nullable) {
                $rules[] = 'nullable';
            } elseif (! $this->hasConditionalRequiredRule($this->extraRules)) {
                $rules[] = 'sometimes';
            }

            $rules[] = 'array';

            return array_merge($rules, $this->extraRules);
        }

        $rules = parent::buildRules($context);
        $rules[] = 'file';

        if (! empty($this->acceptedTypes)) {
            $rules[] = 'mimes:'.implode(',', $this->acceptedTypes);
        }

        if ($this->maxSize !== null) {
            $rules[] = 'max:'.$this->maxSize;
        }

        if (! $this->allowActiveContent) {
            $rules[] = $this->noActiveContentRule();
        }

        return $rules;
    }

    /**
     * The rule that refuses active content (see `NoActiveContent`), checked
     * against the name the upload would be stored under. Handed to the
     * validator as a closure, like the other rules a field builds.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function noActiveContentRule(): Closure
    {
        return (new NoActiveContent($this->acceptedTypes, $this->generateStorageFilename(...)))->validate(...);
    }

    /**
     * Validation rules for each item in a multiple-file array.
     *
     * Only meaningful when multiple() is enabled.
     *
     * @return list<string|Closure>
     */
    public function buildItemRules(): array
    {
        if (! $this->multiple) {
            return [];
        }

        $rules = ['file'];

        if (! empty($this->acceptedTypes)) {
            $rules[] = 'mimes:'.implode(',', $this->acceptedTypes);
        }

        if ($this->maxSize !== null) {
            $rules[] = 'max:'.$this->maxSize;
        }

        if (! $this->allowActiveContent) {
            $rules[] = $this->noActiveContentRule();
        }

        return $rules;
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'disk' => $this->disk,
            'storagePath' => $this->storagePath,
            'maxSize' => $this->maxSize,
            'acceptedTypes' => $this->acceptedTypes,
            'multiple' => $this->multiple,
            'showFileInfo' => $this->showFileInfo,
        ];
    }
}
