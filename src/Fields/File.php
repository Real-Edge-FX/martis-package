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
 */
class File extends Field
{
    protected string $disk = 'public';

    protected string $storagePath = 'uploads';

    protected ?int $maxSize = null; // KB

    /** @var list<string> */
    protected array $acceptedTypes = [];

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

    // -------------------------------------------------------------------------
    // Value lifecycle
    // -------------------------------------------------------------------------

    /**
     * Store the uploaded file and update the model attribute.
     *
     * Accepts:
     *   - UploadedFile  => store on disk, delete old file first
     *   - null / ''     => delete stored file, set attribute to null
     *   - string        => keep as-is (existing path passthrough)
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
     * Resolve the field value for display.
     *
     * Returns null when no file is stored, otherwise:
     *   ['path' => '...', 'url' => '...', 'name' => '...']
     *
     * @return array{path: string, url: string, name: string}|null
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($model->getAttribute($attr), $model, $attr);
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
     * Delete the currently stored file from disk (does NOT update the model attribute).
     */
    public function deleteStoredFile(Model $model): void
    {
        $path = $model->getAttribute($this->attribute);

        if ($path !== null && $path !== '') {
            Storage::disk($this->disk)->delete($path);
        }
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function buildRules(): array
    {
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
        ];
    }
}
