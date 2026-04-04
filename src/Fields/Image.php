<?php

namespace Martis\Fields;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Image upload field.
 *
 * Extends File with image-specific features:
 *   - Validates that uploaded files are images
 *   - Optional thumbnail generation (via GD or Intervention Image)
 *   - Resolves to array with {path, url, name, thumbnailUrl}
 *   - Configurable accepted image types
 *
 * Usage:
 *   Image::make('featured_image')
 *       ->disk('public')
 *       ->storagePath('uploads/images')
 *       ->thumbnail(300, 300)
 *       ->maxSize(5120)
 *       ->preserveOriginalName()
 *       ->sanitizeFileName()
 *       ->nullable()
 *
 *   Image::make('gallery')
 *       ->multiple()
 *       ->thumbnail(200, 200)
 *       ->acceptedTypes(['jpg', 'jpeg', 'png', 'webp'])
 *       ->disk('public')
 */
class Image extends File
{
    protected ?int $thumbnailWidth = null;

    protected ?int $thumbnailHeight = null;

    /** @var list<string> Default accepted image extensions. */
    protected array $acceptedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    public function type(): string
    {
        return 'image';
    }

    // -------------------------------------------------------------------------
    // Thumbnail configuration
    // -------------------------------------------------------------------------

    /**
     * Enable thumbnail generation with optional dimensions.
     *
     * Default: 300x300 (aspect-ratio preserved, fits within bounds).
     */
    public function thumbnail(int $width = 300, int $height = 300): static
    {
        $this->thumbnailWidth = $width;
        $this->thumbnailHeight = $height;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Value lifecycle
    // -------------------------------------------------------------------------

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

            $filename = $this->generateStorageFilename($value);
            $path = $value->storeAs($this->storagePath, $filename, $this->disk);

            if ($path) {
                $model->setAttribute($this->attribute, $path);

                if ($this->thumbnailWidth !== null || $this->thumbnailHeight !== null) {
                    $this->generateThumbnail($path, $value);
                }
            } else {
                $model->setAttribute($this->attribute, null);
            }

            return;
        }

        if ($value === null || $value === '') {
            $this->deleteStoredFile($model);
            $model->setAttribute($this->attribute, null);

            return;
        }

        $model->setAttribute($this->attribute, $value);
    }

    /**
     * Fill for multiple mode with thumbnail support.
     */
    protected function fillMultiple(Model $model, mixed $value): void
    {
        $existingPaths = $this->getExistingPaths($model);

        if (! is_array($value) || $value === []) {
            foreach ($existingPaths as $path) {
                $this->deleteImageAndThumb($path);
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

        // Delete files (and thumbnails) that are no longer kept
        $removedPaths = array_diff($existingPaths, $keepPaths);
        foreach ($removedPaths as $path) {
            $this->deleteImageAndThumb($path);
        }

        // Store new uploads with thumbnails
        $newPaths = [];
        foreach ($rawFiles as $file) {
            if ($file instanceof UploadedFile) {
                $filename = $this->generateStorageFilename($file);
                $path = $file->storeAs($this->storagePath, $filename, $this->disk);
                if ($path) {
                    $newPaths[] = $path;
                    if ($this->thumbnailWidth !== null || $this->thumbnailHeight !== null) {
                        $this->generateThumbnail($path, $file);
                    }
                }
            }
        }

        $allPaths = array_merge($keepPaths, $newPaths);
        $model->setAttribute($this->attribute, json_encode($allPaths));
    }

    /**
     * @return array{path: string, url: string, name: string, thumbnailUrl: string}|list<array{path: string, url: string, name: string, thumbnailUrl: string}>|null
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
        $url = $disk->url($path);
        $thumbPath = $this->getThumbnailPath($path);
        $thumbnailUrl = ($thumbPath !== null && $disk->exists($thumbPath))
            ? $disk->url($thumbPath)
            : $url;

        return [
            'path' => $path,
            'url' => $url,
            'name' => $this->resolveDisplayName($path),
            'thumbnailUrl' => $thumbnailUrl,
        ];
    }

    /**
     * Resolve for multiple mode with thumbnailUrl.
     *
     * @return list<array{path: string, url: string, name: string, thumbnailUrl: string}>
     */
    protected function resolveMultiple(Model $model, string $attr): array
    {
        $paths = $this->getExistingPathsFromRaw($model->getAttribute($attr));

        if (empty($paths)) {
            return [];
        }

        /** @var Cloud $disk */
        $disk = Storage::disk($this->disk);

        return array_map(function (string $path) use ($disk): array {
            $url = $disk->url($path);
            $thumbPath = $this->getThumbnailPath($path);
            $thumbnailUrl = ($thumbPath !== null && $disk->exists($thumbPath))
                ? $disk->url($thumbPath)
                : $url;

            return [
                'path' => $path,
                'url' => $url,
                'name' => $this->resolveDisplayName($path),
                'thumbnailUrl' => $thumbnailUrl,
            ];
        }, $paths);
    }

    /**
     * Delete main image and its thumbnail.
     */
    public function deleteStoredFile(Model $model): void
    {
        if ($this->multiple) {
            $paths = $this->getExistingPaths($model);
            foreach ($paths as $path) {
                $this->deleteImageAndThumb($path);
            }

            return;
        }

        $path = $model->getAttribute($this->attribute);

        if ($path === null || $path === '') {
            return;
        }

        Storage::disk($this->disk)->delete($path);

        $thumbPath = $this->getThumbnailPath($path);
        if ($thumbPath !== null) {
            Storage::disk($this->disk)->delete($thumbPath);
        }
    }

    /**
     * Delete a single image and its thumbnail from disk.
     */
    protected function deleteImageAndThumb(string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk($this->disk)->delete($path);

        $thumbPath = $this->getThumbnailPath($path);
        if ($thumbPath !== null) {
            Storage::disk($this->disk)->delete($thumbPath);
        }
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Replace the generic 'file' rule with 'image' (JPEG, PNG, GIF, WebP, BMP).
     *
     * @return list<string>
     */
    public function buildRules(): array
    {
        $rules = parent::buildRules();

        // Replace 'file' with 'image' (more specific) — only for single mode
        $key = array_search('file', $rules, true);
        if ($key !== false) {
            $rules[$key] = 'image';
        }

        return array_values($rules);
    }

    /**
     * Item rules for multiple mode — use 'image' instead of 'file'.
     *
     * @return list<string>
     */
    public function buildItemRules(): array
    {
        $rules = parent::buildItemRules();

        $key = array_search('file', $rules, true);
        if ($key !== false) {
            $rules[$key] = 'image';
        }

        return array_values($rules);
    }

    // -------------------------------------------------------------------------
    // Thumbnail generation
    // -------------------------------------------------------------------------

    protected function getThumbnailPath(string $path): ?string
    {
        if ($this->thumbnailWidth === null && $this->thumbnailHeight === null) {
            return null;
        }

        $dir = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $prefix = ($dir === '.' || $dir === '') ? '' : $dir.'/';

        return $prefix.$filename.'_thumb.'.$ext;
    }

    protected function generateThumbnail(string $storedPath, UploadedFile $file): void
    {
        if (class_exists('\Intervention\Image\ImageManager')) {
            $this->generateThumbnailWithIntervention($storedPath, $file);
        } else {
            $this->generateThumbnailWithGd($storedPath, $file);
        }
    }

    protected function generateThumbnailWithGd(string $storedPath, UploadedFile $file): void
    {
        $width = $this->thumbnailWidth ?? 300;
        $height = $this->thumbnailHeight ?? 300;
        $mime = $file->getMimeType();
        $sourcePath = $file->getRealPath();

        if ($sourcePath === false) {
            return;
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => false,
        };

        if ($src === false) {
            return;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $ratio = min($width / $srcW, $height / $srcH);
        $thumbW = (int) max(1, round($srcW * $ratio));
        $thumbH = (int) max(1, round($srcH * $ratio));

        assert($thumbW >= 1 && $thumbH >= 1);
        $thumb = imagecreatetruecolor($thumbW, $thumbH);
        if ($thumb === false) {
            imagedestroy($src);

            return;
        }

        if ($mime === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($thumb, 0, 0, $thumbW, $thumbH, $transparent);
            }
        }

        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $thumbW, $thumbH, $srcW, $srcH);

        $thumbPath = $this->getThumbnailPath($storedPath);
        if ($thumbPath === null) {
            imagedestroy($src);
            imagedestroy($thumb);

            return;
        }

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($thumb, null, 85),
            'image/png' => imagepng($thumb),
            'image/gif' => imagegif($thumb),
            'image/webp' => imagewebp($thumb, null, 85),
            default => imagejpeg($thumb, null, 85),
        };
        $content = ob_get_clean();

        if ($content !== false && $content !== '') {
            Storage::disk($this->disk)->put($thumbPath, $content);
        }

        imagedestroy($src);
        imagedestroy($thumb);
    }

    /**
     * Thumbnail generation using Intervention Image v3.
     */
    protected function generateThumbnailWithIntervention(string $storedPath, UploadedFile $file): void
    {
        $thumbPath = $this->getThumbnailPath($storedPath);
        if ($thumbPath === null) {
            return;
        }

        $width = $this->thumbnailWidth ?? 300;
        $height = $this->thumbnailHeight ?? 300;

        $manager = app('Intervention\\Image\\ImageManager');
        assert(is_object($manager));
        // @phpstan-ignore-next-line
        $image = $manager->read($file->getRealPath());
        $image->scaleDown($width, $height);
        $encoded = $image->encode();

        Storage::disk($this->disk)->put($thumbPath, (string) $encoded);
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_merge(parent::extraAttributes(), [
            'thumbnailWidth' => $this->thumbnailWidth,
            'thumbnailHeight' => $this->thumbnailHeight,
        ]);
    }
}
