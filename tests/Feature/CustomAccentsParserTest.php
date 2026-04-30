<?php

declare(strict_types=1);

use Martis\Preferences\CustomAccentsParser;

/*
 * `MARTIS_CUSTOM_ACCENTS` parser (v1.7.0). The parser is intentionally
 * tolerant: invalid entries are dropped silently with a Log::warning
 * so a typo in the env never crashes the request. The Pest cases
 * below pin every drop reason and the happy path.
 */

it('returns an empty array for null and empty input', function () {
    expect(CustomAccentsParser::parse(null))->toBe([]);
    expect(CustomAccentsParser::parse(''))->toBe([]);
    expect(CustomAccentsParser::parse('   '))->toBe([]);
});

it('parses a well-formed comma-separated list', function () {
    $result = CustomAccentsParser::parse('edgeflow:#1a73e8,sunset:#ff6b35');

    expect($result)->toBe([
        'edgeflow' => '#1a73e8',
        'sunset' => '#ff6b35',
    ]);
});

it('tolerates whitespace around commas and colons', function () {
    $result = CustomAccentsParser::parse(' edgeflow : #1a73e8 ,  sunset:#FF6B35 ');

    expect($result)->toHaveKey('edgeflow')
        ->and($result['edgeflow'])->toBe('#1a73e8')
        ->and($result['sunset'])->toBe('#ff6b35'); // hex normalised to lowercase
});

it('drops malformed entries (missing colon)', function () {
    $result = CustomAccentsParser::parse('edgeflow:#1a73e8,broken_no_colon,sunset:#ff6b35');

    expect($result)->toBe([
        'edgeflow' => '#1a73e8',
        'sunset' => '#ff6b35',
    ]);
});

it('drops invalid hex (wrong length, missing #, non-hex chars)', function () {
    $result = CustomAccentsParser::parse(
        'a:#1a73e8,b:1a73e8,c:#1a73e,d:#zzzzzz,e:#1a73e88,f:#abc',
    );

    // Only `a` survives; `b`-`f` fail hex validation.
    expect($result)->toBe(['a' => '#1a73e8']);
});

it('drops invalid names (uppercase, leading digit, special chars, too long)', function () {
    $result = CustomAccentsParser::parse(
        'EdgeFlow:#1a73e8,1bad:#1a73e8,with space:#1a73e8,'.str_repeat('x', 40).':#1a73e8,ok:#abcdef',
    );

    expect($result)->toBe(['ok' => '#abcdef']);
});

it('rejects names that collide with bundled enum values', function () {
    $result = CustomAccentsParser::parse('martis:#aaaaaa,blue:#bbbbbb,teal:#cccccc,violet:#dddddd,amber:#eeeeee,custom:#ffffff,edgeflow:#1a73e8');

    expect($result)->toBe(['edgeflow' => '#1a73e8']);
});

it('last-wins when the same name appears twice (env override semantics)', function () {
    $result = CustomAccentsParser::parse('edgeflow:#aaaaaa,edgeflow:#1a73e8');

    expect($result)->toBe(['edgeflow' => '#1a73e8']);
});

it('truncates beyond the MAX_ACCENTS limit', function () {
    $entries = [];
    for ($i = 0; $i < CustomAccentsParser::MAX_ACCENTS + 5; $i++) {
        $entries[] = sprintf('color%02d:#%06x', $i, $i);
    }

    $result = CustomAccentsParser::parse(implode(',', $entries));

    expect(count($result))->toBe(CustomAccentsParser::MAX_ACCENTS);
});
