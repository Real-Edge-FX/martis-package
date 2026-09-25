<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;
use Martis\Support\TranslatedLine;

it('returns the translated line with its replacements', function () {
    Lang::addLines(['messages.greeting' => 'Hello, :name'], 'en', 'martis-test');

    expect(TranslatedLine::get('martis-test::messages.greeting', ['name' => 'Ana']))->toBe('Hello, Ana');
});

it('returns a shipped Martis line as a string', function () {
    expect(TranslatedLine::get('martis::messages.unauthorized'))
        ->toBeString()
        ->not->toBe('martis::messages.unauthorized');
});

it('falls back to the key when the key names a group of lines', function () {
    Lang::addLines(['messages.group.a' => 'A', 'messages.group.b' => 'B'], 'en', 'martis-test');

    expect(__('martis-test::messages.group'))->toBeArray()
        ->and(TranslatedLine::get('martis-test::messages.group'))->toBe('martis-test::messages.group');
});

it('falls back to the key when the line is missing', function () {
    expect(TranslatedLine::get('martis-test::messages.missing'))->toBe('martis-test::messages.missing');
});
