<?php

use Illuminate\Support\Facades\Validator;
use Martis\Fields\Gravatar;

// In URL mode the stored value is rendered as an <img src> for every viewer,
// so a write accepts an absolute https:// URL only (F052).

/**
 * Validate `$value` the way a write validates the field.
 *
 * @return list<string>
 */
function gravatarUrlErrors(Gravatar $field, mixed $value): array
{
    $validator = Validator::make(
        ['avatar_url' => $value],
        ['avatar_url' => $field->buildRules('update')],
        [],
        ['avatar_url' => 'Avatar URL'],
    );

    return $validator->fails() ? $validator->errors()->get('avatar_url') : [];
}

it('accepts an https URL in URL mode', function () {
    expect(gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl(), 'https://cdn.example.com/a.png?s=40'))->toBe([]);
});

it('rejects a value that is not an absolute https URL in URL mode', function (mixed $value) {
    $errors = gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl(), $value);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('Avatar URL')
        ->and($errors[0])->toContain('https://');
})->with([
    'http' => 'http://example.com/a.png',
    'javascript' => 'javascript:alert(1)',
    'data URL' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
    'protocol relative' => '//example.com/a.png',
    'bare host' => 'example.com/a.png',
    'text' => 'not a url',
    'array' => [['https://example.com']],
]);

it('lets an empty value through, as fill() writes nothing for it', function () {
    expect(gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl()->nullable(), null))->toBe([])
        ->and(gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl(), null))->toBe([]);
});

it('adds no URL rule in email mode, which writes nothing', function () {
    expect(gravatarUrlErrors(Gravatar::make('avatar_url'), 'http://example.com/a.png'))->toBe([]);
});

it('adds no URL rule to a readonly field, which writes nothing', function () {
    expect(gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl()->readonly(), 'http://example.com/a.png'))->toBe([])
        ->and(gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl()->readonly(fn () => true), 'http://example.com/a.png'))->toBe([]);
});

it('translates the message', function () {
    foreach (['en', 'pt_PT', 'pt_BR'] as $locale) {
        app()->setLocale($locale);

        $errors = gravatarUrlErrors(Gravatar::make('avatar_url')->fromUrl(), 'http://example.com/a.png');

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->not->toContain('martis::')
            ->and($errors[0])->toContain('https://');
    }
});
