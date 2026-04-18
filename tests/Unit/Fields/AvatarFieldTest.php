<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\Fields\Avatar;

class AvatarTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['avatar_path', 'name'];

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
        fn ($m) => "https://ui-avatars.com/api/?name=".urlencode($m->name)
    );

    $resolved = $field->resolve($model);

    expect($resolved['url'])->toBe('https://ui-avatars.com/api/?name=Jane+Doe')
        ->and($resolved['isFallback'])->toBeTrue();
});

it('Avatar fallback is marked on resolved payload so the frontend can identify it', function () {
    // When the stored attribute is absent, fallback kicks in and the
    // resolved payload must flag `isFallback: true` so the frontend
    // can style it differently (e.g. slightly muted).
    $model = new AvatarTestModel(['name' => 'No Avatar']);
    $field = Avatar::make('avatar_path')->fallback('https://cdn.example.com/default.png');

    $resolved = $field->resolve($model);

    expect($resolved['isFallback'])->toBeTrue()
        ->and($resolved['thumbnailUrl'])->toBe('https://cdn.example.com/default.png');
});
