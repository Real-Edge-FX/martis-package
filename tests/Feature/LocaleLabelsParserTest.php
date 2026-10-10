<?php

declare(strict_types=1);

use Martis\Preferences\LocaleLabelsParser;

/*
 * `MARTIS_UI_LOCALE_LABELS` parser (v2.10.0). Unlike CustomAccentsParser,
 * a malformed segment throws: a set-but-unusable config value fails
 * loudly, naming the env variable and the bad segment.
 */

it('parses comma-separated code:label pairs', function () {
    expect(LocaleLabelsParser::parse('en_GB:English (UK),pt_PT:Português (Portugal)'))->toBe([
        'en_GB' => 'English (UK)',
        'pt_PT' => 'Português (Portugal)',
    ]);
});

it('returns an empty map for null, empty and blank input', function () {
    expect(LocaleLabelsParser::parse(null))->toBe([])
        ->and(LocaleLabelsParser::parse(''))->toBe([])
        ->and(LocaleLabelsParser::parse('  '))->toBe([])
        ->and(LocaleLabelsParser::parse(' , ,'))->toBe([]);
});

it('tolerates whitespace around segments, codes and labels', function () {
    expect(LocaleLabelsParser::parse("  en_GB : English (UK) ,\n pt_PT:Português "))->toBe([
        'en_GB' => 'English (UK)',
        'pt_PT' => 'Português',
    ]);
});

it('keeps duplicates last-wins', function () {
    expect(LocaleLabelsParser::parse('en:One,en:Two'))->toBe(['en' => 'Two']);
});

it('splits on the first colon only, so a label may contain colons', function () {
    expect(LocaleLabelsParser::parse('en_GB:English: United Kingdom'))->toBe(['en_GB' => 'English: United Kingdom']);
});

it('keeps the code as given (underscore or hyphen)', function () {
    expect(LocaleLabelsParser::parse('en_GB:UK,zh-Hant:Chinese'))->toBe(['en_GB' => 'UK', 'zh-Hant' => 'Chinese']);
});

it('throws naming the env variable and the segment when a segment has no colon', function () {
    expect(fn () => LocaleLabelsParser::parse('en_GB:English,pt_PT'))
        ->toThrow(InvalidArgumentException::class, 'MARTIS_UI_LOCALE_LABELS');
    expect(fn () => LocaleLabelsParser::parse('en_GB:English,pt_PT'))
        ->toThrow(InvalidArgumentException::class, '"pt_PT"');
});

it('throws on an empty code or an empty label', function (string $raw) {
    expect(fn () => LocaleLabelsParser::parse($raw))
        ->toThrow(InvalidArgumentException::class, 'MARTIS_UI_LOCALE_LABELS');
})->with([':English', 'en_GB:', 'en_GB:   ']);

it('throws on an invalid locale code', function (string $raw) {
    expect(fn () => LocaleLabelsParser::parse($raw))
        ->toThrow(InvalidArgumentException::class, 'MARTIS_UI_LOCALE_LABELS');
})->with(['not a locale:X', 'e:X', '1234:X', 'en__GB:X', 'en_:X']);

it('validates locale codes', function () {
    foreach (['en', 'pt_PT', 'pt-BR', 'en_GB', 'zh_Hant_TW', 'fil'] as $code) {
        expect(LocaleLabelsParser::isValidCode($code))->toBeTrue($code);
    }
    foreach (['', 'e', 'en_', '_GB', 'en GB', 'en/GB', '../en', 'en_GB.php', 'abcd'] as $code) {
        expect(LocaleLabelsParser::isValidCode($code))->toBeFalse($code);
    }
});
