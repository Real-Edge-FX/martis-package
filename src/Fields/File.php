<?php

namespace Martis\Fields;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * File upload field.
 *
 * Stores any uploaded file on a configurable disk.
 * Resolves to an array with {path, url, name} for frontend rendering.
 *
 * Usage:
 *   File::make('attachment')
 *       ->disk('s3')
 *       ->storagePath('uploads/docs')
 *       ->maxSize(10240)   // 10MB in KB
 *       ->acceptedTypes(['pdf', 'doc', 'docx'])
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

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    // -------------------------------------------------------------------------
    // Value lifecycle
    // -------------------------------------------------------------------------

    /**
     * Store the uploaded file and update the model attribute.
     *
     * Single mode accepts:
     *   - UploadedFile  => store on disk, delete old file first
     *   - null / ''     => delete stored file, set attribute to null
     *   - string        => keep as-is (existing path passthrough)
     *
     * Multiple mode accepts:
     *   - array{files: list<UploadedFile>, existing: list<string>}
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute);

            return;
        }

        if ($this->multiple) {
            $this->fillMultiple($model, $value);

            return;
        }

        if ($value instanceof UploadedFile) {
            $this->deleteStoredFile($model);
            $path = $value->store($this->storagePath, $this->disk);
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
                $path = $file->store($this->storagePath, $this->disk);
                if ($path) {
                    $newPaths[] = $path;
                }
            }
        }

        $allPaths = array_merge($keepPaths, $newPaths);
        $model->setAttribute($this->attribute, json_encode($allPaths));
    }

    /**
     * Resolve the field value for display.
     *
     * Single mode returns null or {path, url, name}.
     * Multiple mode returns array of {path, url, name} objects.
     *
     * @return array{path: string, url: string, name: string}|list<array{path: string, url: string, name: string}>|null
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attr), $model, $attr);
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
            'name' => basename($path),
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
            'name' => basename($path),
        ], $paths);
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

    /**
     * @return list<string>
     */
    public function buildRules(): array
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

        $rules = parent::buildRules();
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
        ];
    }
}
