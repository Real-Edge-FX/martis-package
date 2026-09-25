<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Martis\Support\Initials;

/**
 * Martis\Support\Initials gives every identity avatar its letters and its
 * slot of the theme's `--martis-avatar-N` tokens. The slots below are
 * pinned in `resources/js/lib/avatarPalette.test.ts` too: the browser's
 * `avatarPaletteSlot()` must land every seed on the same slot.
 */
dataset('palette slots shared with the browser', [
    'two words' => ['Ada Lovelace', 11],
    'two words, positive hash' => ['Grace Hopper', 6],
    'an e-mail' => ['jane@example.com', 5],
    'accented letters' => ['José Álvares', 9],
    'CJK' => ['李小龍', 16],
    'an emoji (a surrogate pair in UTF-16)' => ['🙂 Smile', 15],
    'a diaeresis' => ['Zoë', 10],
    'one letter' => ['a', 7],
    'the field tests\' seed' => ['Jane Doe', 6],
    'a longer name' => ['Margaret Hamilton', 5],
]);

it('lands a seed on the same palette slot as the browser', function (string $seed, int $slot) {
    expect(Initials::paletteSlot($seed))->toBe($slot);
})->with('palette slots shared with the browser');

it('gives an empty seed the neutral slot 16', function () {
    expect(Initials::paletteSlot(''))->toBe(16);
});

it('takes the first letter of the first and of the last word', function (string $seed, string $initials) {
    expect(Initials::of($seed))->toBe($initials);
})->with([
    'two words' => ['Ada Lovelace', 'AL'],
    'three words' => ['Ada King Lovelace', 'AL'],
    'one word' => ['Ada', 'A'],
    'extra whitespace' => ["  ada \t  lovelace  ", 'AL'],
    'accented letters' => ['josé álvares', 'JÁ'],
    'CJK' => ['李小龍', '李'],
    'blank' => ['   ', ''],
    'empty' => ['', ''],
]);

it('keeps DEFAULT_COLORS equal to the --martis-avatar-N tokens of martis.css', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/martis.css');
    preg_match_all('/--martis-avatar-(\d+):\s*(#[0-9A-Fa-f]{6})/', (string) $css, $matches, PREG_SET_ORDER);

    // The palette is declared twice (dark and light) with the same values.
    expect($matches)->toHaveCount(2 * Initials::PALETTE_SIZE);
    foreach ($matches as [, $slot, $hex]) {
        expect(Initials::defaultColor((int) $slot))->toBe(strtolower($hex));
    }
});

it('rejects a palette slot outside 1 to 16', function (int $slot) {
    expect(fn () => Initials::defaultColor($slot))
        ->toThrow(InvalidArgumentException::class, "Avatar palette slot {$slot} does not exist: the slots are 1 to 16.");
})->with([0, 17]);

it('gives a user the initials and slot of the name, else of the e-mail', function (array $attributes, string $initials, int $slot) {
    $user = (new User)->forceFill($attributes);

    expect(Initials::forUser($user))->toBe(['avatar_initials' => $initials, 'avatar_palette' => $slot]);
})->with([
    'a name' => [['name' => 'Ada Lovelace', 'email' => 'jane@example.com'], 'AL', 11],
    'a blank name' => [['name' => '  ', 'email' => 'jane@example.com'], 'J', 5],
    'no name' => [['email' => 'jane@example.com'], 'J', 5],
    'neither' => [[], '', 16],
]);
