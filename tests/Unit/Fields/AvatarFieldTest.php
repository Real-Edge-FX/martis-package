<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\Fields\Avatar;

class AvatarTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['avatar_path', 'name', 'brand_color'];

    public $timestamps = false;
}

it('Avatar::make creates an avatar field', function () {
    $field = Avatar::make('avatar_path');

    expect($field->type())->toBe('avatar');
});

it('Avatar default shape is Circle', function () {
    expect(Avatar::make('avatar_path')->getShape())->toBe(AvatarShape::Circle);
});

it('Avatar shape setters switch variants', function () {
    expect(Avatar::make('avatar_path')->rounded()->getShape())->toBe(AvatarShape::Rounded)
        ->and(Avatar::make('avatar_path')->squared()->getShape())->toBe(AvatarShape::Squared)
        ->and(Avatar::make('avatar_path')->circle()->getShape())->toBe(AvatarShape::Circle);
});

it('Avatar shape is emitted in the schema', function () {
    $schema = Avatar::make('avatar_path')->rounded()->toArray();

    expect($schema['shape'])->toBe('rounded');
});

// ⭐ differentials

it('Avatar fallback(url) resolves to the static URL when the stored file is missing', function () {
    $model = new AvatarTestModel(['name' => 'Jane']);
    $field = Avatar::make('avatar_path')->fallback('https://cdn.example.com/default.png');

    $resolved = $field->resolve($model);

    expect($resolved)->toBeArray()
        ->and($resolved['url'])->toBe('https://cdn.example.com/default.png')
        ->and($resolved['isFallback'])->toBeTrue();
});

it('Avatar fallback(Closure) receives the model for per-row fallbacks', function () {
    $model = new AvatarTestModel(['name' => 'Jane Doe']);
    $field = Avatar::make('avatar_path')->fallback(
        fn ($m) => "https://cdn.example.com/".urlencode($m->name).".png"
    );

    $resolved = $field->resolve($model);

    expect($resolved['url'])->toBe('https://cdn.example.com/Jane+Doe.png')
        ->and($resolved['isFallback'])->toBeTrue();
});

// ⭐ Zero-config inline initials fallback (no external service)

it('Avatar without upload or explicit fallback emits an initials payload', function () {
    $model = new AvatarTestModel(['name' => 'Jane Doe']);
    $field = Avatar::make('avatar_path');

    $resolved = $field->resolve($model);

    expect($resolved['isInitialsFallback'])->toBeTrue()
        ->and($resolved['initials'])->toBe('JD')
        ->and($resolved['color'])->toStartWith('#')
        ->and($resolved['seed'])->toBe('Jane Doe')
        ->and($resolved['url'])->toBeNull();
});

it('Avatar initials fallback uses the `name` attribute by default', function () {
    $model = new AvatarTestModel(['name' => 'Catarina Martins']);
    $field = Avatar::make('avatar_path');

    expect($field->resolve($model)['initials'])->toBe('CM');
});

it('Avatar initialsFrom() decouples the seed from the default `name` attribute', function () {
    $model = new AvatarTestModel(['name' => 'N/A']);
    $model->setAttribute('display_name', 'Rui Santos');

    $field = Avatar::make('avatar_path')->initialsFrom('display_name');

    expect($field->resolve($model)['initials'])->toBe('RS');
});

it('Avatar colorFrom() pulls the initials background from a model attribute', function () {
    $model = new AvatarTestModel(['name' => 'Jane', 'brand_color' => '#ec4899']);
    $field = Avatar::make('avatar_path')->colorFrom('brand_color');

    expect($field->resolve($model)['color'])->toBe('#ec4899');
});

it('Avatar initials fallback uses a deterministic palette when no colorFrom is set', function () {
    $field = Avatar::make('avatar_path');

    $a = $field->resolve(new AvatarTestModel(['name' => 'Jane Doe']))['color'];
    $b = $field->resolve(new AvatarTestModel(['name' => 'Jane Doe']))['color'];

    expect($a)->toBe($b);
});

it('Avatar developer-provided fallback() wins over the built-in initials fallback', function () {
    $model = new AvatarTestModel(['name' => 'Jane']);
    $field = Avatar::make('avatar_path')->fallback('https://cdn.example.com/x.png');

    $resolved = $field->resolve($model);

    expect($resolved['isFallback'])->toBeTrue()
        ->and($resolved)->not->toHaveKey('isInitialsFallback');
});

it('Avatar initials(Closure) overrides the default first+last computation', function () {
    $model = new AvatarTestModel(['name' => 'Jane Q. Doe']);
    $field = Avatar::make('avatar_path')->initials(fn ($seed) => mb_substr($seed, 0, 3));

    expect($field->resolve($model)['initials'])->toBe('JAN');
});
