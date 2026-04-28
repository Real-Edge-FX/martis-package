<?php

namespace Martis\Fields;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
 *       ->acceptedTypes(['pdf', 'doc', 'docx'])
 *       ->preserveOriginalName()
 *       ->sanitizeFileName()
 *       ->nullable()
 *
 *   File::make('documents')
 *       ->multiple()
 *       ->disk('public')
 *       ->storagePath('uploads/docs')
 *       ->maxSize(5120)
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
     * @param  list<string>  $mimes
     */
    public function acceptedTypes(array $mimes): static
    {
        $this->acceptedTypes = $mimes;

        return $this;
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
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        if ($this->multiple) {
            $this->fillMultiple($model, $value);

            return;
        }

        if ($value instanceof UploadedFile) {
            $this->deleteStoredFile($model);
            $filename = $this->generateStorageFilename($value);
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
            $model->setAttribute($this->attribute, json_encode([]));

            return;
        }

        /** @var array<mixed> $rawFiles */
        $rawFiles = $value['files'] ?? [];
        /** @var array<mixed> $rawExisting */
        $rawExisting = $value['existing'] ?? [];

        /** @var list<string> $keepPaths */
        $keepPaths = [];
        foreach ($rawExisting as $p) {
            if (is_string($p) && $p !== '') {
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
        foreach ($rawFiles as $file) {
            if ($file instanceof UploadedFile) {
                $filename = $this->generateStorageFilename($file);
                $path = $file->storeAs($this->storagePath, $filename, $this->disk);
                if ($path) {
                    $newPaths[] = $path;
                }
            }
        }

        $allPaths = array_merge($keepPaths, $newPaths);
        $model->setAttribute($this->attribute, json_encode($allPaths));
    }

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attr), $model, $attr, $this->safeRequest());
        }

        if ($this->multiple) {
            return $this->resolveMultiple($model, $attr);
        }

        $path = $model->getAttribute($attr);

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
        $paths = $this->getExistingPathsFromRaw($model->getAttribute($attr));

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
            } else {
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

        return $rules;
    }

    /**
     * Validation rules for each item in a multiple-file array.
     *
     * Only meaningful when multiple() is enabled.
     *
     * @return list<string>
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
