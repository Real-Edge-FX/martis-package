<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\Image;

class ImageFieldTestModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Storage::fake('public');
});

// ---------------------------------------------------------------------------
// thumbnail(Closure) — Closure-driven thumbnail URL (v1.1)
// ---------------------------------------------------------------------------

it('thumbnail(Closure) overrides disk-based thumbnail resolution with the closure return', function () {
    $field = Image::make('avatar')->thumbnail(
        fn ($value, $model) => "https://cdn.example/thumbs/{$model->id}.jpg",
    );

    Storage::disk('public')->put('uploads/test.png', 'fake-image-bytes');
    $model = new ImageFieldTestModel(['id' => 42, 'avatar' => 'uploads/test.png']);

    $resolved = $field->resolve($model);

    expect($resolved)->toBeArray();
    expect($resolved['thumbnailUrl'])->toBe('https://cdn.example/thumbs/42.jpg');
});

it('thumbnail(int, int) keeps the disk-based behaviour and disables the closure path', function () {
    $field = Image::make('avatar')->thumbnail(150, 150);
    $reflection = new ReflectionProperty(Image::class, 'thumbnailResolver');
    $reflection->setAccessible(true);

    expect($reflection->getValue($field))->toBeNull();
});

it('thumbnail(Closure) clears any previously-set dimensions so disk generation is skipped', function () {
    $field = Image::make('avatar')
        ->thumbnail(300, 300)
        ->thumbnail(fn ($v, $m) => 'https://cdn/x.jpg');

    $widthRef = new ReflectionProperty(Image::class, 'thumbnailWidth');
    $heightRef = new ReflectionProperty(Image::class, 'thumbnailHeight');
    $widthRef->setAccessible(true);
    $heightRef->setAccessible(true);

    expect($widthRef->getValue($field))->toBeNull();
    expect($heightRef->getValue($field))->toBeNull();
});

// ---------------------------------------------------------------------------
// preview(Closure) — full-size preview URL resolver (v1.1)
// ---------------------------------------------------------------------------

it('preview(Closure) overrides disk->url() for the main URL', function () {
    $field = Image::make('avatar')->preview(
        fn ($value, $model) => "https://cdn.example/full/{$model->id}.jpg",
    );

    Storage::disk('public')->put('uploads/test.png', 'fake-image-bytes');
    $model = new ImageFieldTestModel(['id' => 99, 'avatar' => 'uploads/test.png']);

    $resolved = $field->resolve($model);

    expect($resolved['url'])->toBe('https://cdn.example/full/99.jpg');
});

it('preview(Closure) returning null falls back to disk->url()', function () {
    $field = Image::make('avatar')->preview(fn ($v, $m) => null);

    Storage::disk('public')->put('uploads/test.png', 'fake-image-bytes');
    $model = new ImageFieldTestModel(['avatar' => 'uploads/test.png']);

    $resolved = $field->resolve($model);

    // The fake disk returns a path-based URL when the closure returns null.
    expect($resolved['url'])->toContain('uploads/test.png');
});

it('preview + thumbnail Closures compose without interfering with each other', function () {
    $field = Image::make('avatar')
        ->preview(fn ($v, $m) => "https://cdn/full/{$m->id}.jpg")
        ->thumbnail(fn ($v, $m) => "https://cdn/thumb/{$m->id}.jpg");

    Storage::disk('public')->put('uploads/test.png', 'fake-image-bytes');
    $model = new ImageFieldTestModel(['id' => 7, 'avatar' => 'uploads/test.png']);

    $resolved = $field->resolve($model);

    expect($resolved['url'])->toBe('https://cdn/full/7.jpg');
    expect($resolved['thumbnailUrl'])->toBe('https://cdn/thumb/7.jpg');
});

// ---------------------------------------------------------------------------
// multiple() + Closure resolvers — resolveMultiple must honour closures
// ---------------------------------------------------------------------------

it('thumbnail(Closure) applies to every item in multiple mode', function () {
    $field = Image::make('gallery')
        ->multiple()
        ->thumbnail(fn ($value, $model) => "https://cdn.example/thumbs/{$model->id}/{$value}");

    Storage::disk('public')->put('uploads/a.png', 'fake');
    Storage::disk('public')->put('uploads/b.png', 'fake');
    $paths = json_encode(['uploads/a.png', 'uploads/b.png']);
    $model = new ImageFieldTestModel(['id' => 10, 'gallery' => $paths]);

    $resolved = $field->resolve($model);

    expect($resolved)->toBeArray()->toHaveCount(2);
    expect($resolved[0]['thumbnailUrl'])->toBe('https://cdn.example/thumbs/10/uploads/a.png');
    expect($resolved[1]['thumbnailUrl'])->toBe('https://cdn.example/thumbs/10/uploads/b.png');
});

it('preview(Closure) applies to every item in multiple mode', function () {
    $field = Image::make('gallery')
        ->multiple()
        ->preview(fn ($value, $model) => "https://cdn.example/full/{$model->id}/{$value}");

    Storage::disk('public')->put('uploads/a.png', 'fake');
    $paths = json_encode(['uploads/a.png']);
    $model = new ImageFieldTestModel(['id' => 5, 'gallery' => $paths]);

    $resolved = $field->resolve($model);

    expect($resolved)->toBeArray()->toHaveCount(1);
    expect($resolved[0]['url'])->toBe('https://cdn.example/full/5/uploads/a.png');
});

it('thumbnail(Closure) returning null falls back to the preview URL in multiple mode', function () {
    $field = Image::make('gallery')
        ->multiple()
        ->preview(fn ($v, $m) => 'https://cdn.example/full/x.png')
        ->thumbnail(fn ($v, $m) => null);

    Storage::disk('public')->put('uploads/a.png', 'fake');
    $model = new ImageFieldTestModel(['id' => 1, 'gallery' => json_encode(['uploads/a.png'])]);

    $resolved = $field->resolve($model);

    expect($resolved[0]['url'])->toBe('https://cdn.example/full/x.png');
    expect($resolved[0]['thumbnailUrl'])->toBe('https://cdn.example/full/x.png');
});
