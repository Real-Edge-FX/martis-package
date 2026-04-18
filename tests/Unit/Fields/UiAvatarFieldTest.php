<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\Fields\UiAvatar;

class UiAvatarTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['name', 'brand_color'];

    public $timestamps = false;
}

it('UiAvatar::make creates a display-only ui_avatar field', function () {
    $field = UiAvatar::make('name');

    expect($field->type())->toBe('ui_avatar')
        ->and($field->getShape())->toBe(AvatarShape::Circle);
});

it('UiAvatar resolves initials from the first + last token of the seed', function () {
    $model = new UiAvatarTestModel(['name' => 'Jane Doe']);
    $field = UiAvatar::make('name');

    $payload = $field->resolve($model);

    expect($payload['initials'])->toBe('JD')
        ->and($payload['seed'])->toBe('Jane Doe');
});

it('UiAvatar resolves a single-word seed to a single initial', function () {
    $model = new UiAvatarTestModel(['name' => 'Cher']);
    $field = UiAvatar::make('name');

    expect($field->resolve($model)['initials'])->toBe('C');
});

it('UiAvatar resolves empty seed to empty initials without crashing', function () {
    $model = new UiAvatarTestModel(['name' => null]);
    $field = UiAvatar::make('name');

    $payload = $field->resolve($model);
    expect($payload['initials'])->toBe('');
});

// ⭐ differentials

it('UiAvatar deterministic palette — same seed always yields the same colour', function () {
    $m = new UiAvatarTestModel(['name' => 'Jane Doe']);
    $field = UiAvatar::make('name');

    $a = $field->resolve($m)['color'];
    $b = $field->resolve($m)['color'];
    $c = $field->resolve(new UiAvatarTestModel(['name' => 'Jane Doe']))['color'];

    expect($a)->toBe($b)->and($b)->toBe($c)->and($a)->toStartWith('#');
});

it('UiAvatar different seeds land on different palette slots (statistically)', function () {
    $field = UiAvatar::make('name');
    $names = ['Alice', 'Bob', 'Charlie', 'Diana', 'Eve', 'Frank', 'Gina', 'Hank'];

    $colors = array_map(fn ($n) => $field->resolve(new UiAvatarTestModel(['name' => $n]))['color'], $names);

    // At least 3 distinct palette slots across 8 names.
    expect(count(array_unique($colors)))->toBeGreaterThanOrEqual(3);
});

it('UiAvatar colorFrom(attribute) overrides the deterministic palette', function () {
    $m = new UiAvatarTestModel(['name' => 'Jane', 'brand_color' => '#ff00aa']);
    $field = UiAvatar::make('name')->colorFrom('brand_color');

    expect($field->resolve($m)['color'])->toBe('#ff00aa');
});

it('UiAvatar colorFrom falls back to deterministic when the attribute is empty', function () {
    $m = new UiAvatarTestModel(['name' => 'Jane', 'brand_color' => null]);
    $field = UiAvatar::make('name')->colorFrom('brand_color');

    expect($field->resolve($m)['color'])->toStartWith('#');
});

it('UiAvatar initials(Closure) overrides the default computation', function () {
    $m = new UiAvatarTestModel(['name' => 'Jane Q. Doe']);
    $field = UiAvatar::make('name')->initials(fn ($seed) => mb_substr($seed, 0, 3));

    expect($field->resolve($m)['initials'])->toBe('JAN');
});

it('UiAvatar from(attribute) decouples the seed from the field attribute', function () {
    $m = new UiAvatarTestModel(['name' => 'Jane Doe']);
    $field = UiAvatar::make('avatar_ui')->from('name');

    $payload = $field->resolve($m);
    expect($payload['initials'])->toBe('JD')
        ->and($field->getSeedAttribute())->toBe('name');
});

it('UiAvatar shape is serialised in the schema and the resolved payload', function () {
    $m = new UiAvatarTestModel(['name' => 'Jane']);
    $field = UiAvatar::make('name')->rounded();

    expect($field->toArray()['shape'])->toBe('rounded')
        ->and($field->resolve($m)['shape'])->toBe('rounded');
});
