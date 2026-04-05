<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\AvatarShape;
use Martis\FieldContext;
use Martis\Fields\Gravatar;

class GravatarTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['email'];

    public $timestamps = false;
}

it('Gravatar::make creates a gravatar field with email default', function () {
    $field = Gravatar::make();
    expect($field->attribute())->toBe('email')
        ->and($field->label())->toBe('Avatar')
        ->and($field->type())->toBe('gravatar');
});

it('Gravatar::make accepts custom attribute', function () {
    $field = Gravatar::make('work_email', 'Work Avatar');
    expect($field->attribute())->toBe('work_email')
        ->and($field->label())->toBe('Work Avatar');
});

it('Gravatar is hidden from forms by default', function () {
    $field = Gravatar::make();
    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

it('Gravatar defaults to rounded shape', function () {
    $field = Gravatar::make();
    expect($field->getShape())->toBe(AvatarShape::Rounded);
});

it('Gravatar squared() sets square shape', function () {
    $field = Gravatar::make()->squared();
    expect($field->getShape())->toBe(AvatarShape::Squared);
});

it('Gravatar rounded() sets round shape', function () {
    $field = Gravatar::make()->squared()->rounded();
    expect($field->getShape())->toBe(AvatarShape::Rounded);
});

it('Gravatar size() sets avatar size', function () {
    $field = Gravatar::make()->size(80);
    expect($field->getSize())->toBe(80);
});

it('Gravatar gravatarUrl generates correct URL', function () {
    $url = Gravatar::gravatarUrl('test@example.com');
    $hash = md5('test@example.com');
    expect($url)->toBe("https://www.gravatar.com/avatar/{$hash}?s=40&d=mp");
});

it('Gravatar gravatarUrl trims and lowercases email', function () {
    $url1 = Gravatar::gravatarUrl('Test@Example.COM');
    $url2 = Gravatar::gravatarUrl(' test@example.com ');
    expect($url1)->toBe($url2);
});

it('Gravatar resolves to URL from model email', function () {
    $model = new GravatarTestModel(['email' => 'user@test.com']);
    $field = Gravatar::make();
    $result = $field->resolve($model);
    $hash = md5('user@test.com');
    expect($result)->toBe("https://www.gravatar.com/avatar/{$hash}?s=40&d=mp");
});

it('Gravatar resolves null when email is null', function () {
    $model = new GravatarTestModel(['email' => null]);
    $field = Gravatar::make();
    expect($field->resolve($model))->toBeNull();
});

it('Gravatar resolves null when email is empty', function () {
    $model = new GravatarTestModel(['email' => '']);
    $field = Gravatar::make();
    expect($field->resolve($model))->toBeNull();
});

it('Gravatar fill saves value when provided', function () {
    $model = new GravatarTestModel(['email' => 'original@test.com']);
    $field = Gravatar::make();
    $field->fill($model, 'new@test.com');
    expect($model->getAttribute('email'))->toBe('new@test.com');
});

it('Gravatar fill does not overwrite when value is null', function () {
    $model = new GravatarTestModel(['email' => 'original@test.com']);
    $field = Gravatar::make();
    $field->fill($model, null);
    expect($model->getAttribute('email'))->toBe('original@test.com');
});

it('Gravatar toArray contains shape and size', function () {
    $field = Gravatar::make()->squared()->size(80);
    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'type', 'shape', 'avatarSize'])
        ->and($arr['type'])->toBe('gravatar')
        ->and($arr['shape'])->toBe('squared')
        ->and($arr['avatarSize'])->toBe(80);
});

it('Gravatar respects resolveUsing callback', function () {
    $model = new GravatarTestModel(['email' => 'user@test.com']);
    $field = Gravatar::make()->resolveUsing(fn () => 'custom-url');
    expect($field->resolve($model))->toBe('custom-url');
});
