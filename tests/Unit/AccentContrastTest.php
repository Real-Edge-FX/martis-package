<?php

declare(strict_types=1);

use Martis\Preferences\AccentContrast;

/*
 * `--martis-accent-contrast` for a hex the package derives a palette from
 * (MARTIS_CUSTOM_ACCENTS, per-user brandColor): white on a dark accent, a
 * near-black navy on a bright one, chosen by WCAG contrast ratio.
 */

it('picks white for dark and mid-tone accents', function (string $hex) {
    expect(AccentContrast::for($hex))->toBe(AccentContrast::LIGHT);
})->with(['#4F7BF9', '#0070F0', '#1a73e8', '#7C3AED', '#0D9488', '#B45309', '#000000']);

it('picks the dark navy for bright accents white text cannot hold AA on', function (string $hex) {
    expect(AccentContrast::for($hex))->toBe(AccentContrast::DARK);
})->with(['#C6F135', '#16E7D8', '#F59E0B', '#FFFFFF', '#FDE047']);

it('is case-insensitive and tolerates 3-digit hex', function () {
    expect(AccentContrast::for('#c6f135'))->toBe(AccentContrast::DARK);
    expect(AccentContrast::for('#fff'))->toBe(AccentContrast::DARK);
    expect(AccentContrast::for('#000'))->toBe(AccentContrast::LIGHT);
});

it('falls back to white for a malformed value', function () {
    expect(AccentContrast::for('not-a-colour'))->toBe(AccentContrast::LIGHT);
});
