<?php

declare(strict_types=1);

use Martis\Support\ThemeFiles;

/*
 * martis:publish-assets publishes a theme source only when the panel can load
 * it: app.blade.php drops a `martis.theme.name` outside the same pattern, so a
 * published file under another name would never be linked.
 */

it('accepts theme names made of letters, digits, dashes and underscores', function (string $name) {
    expect(ThemeFiles::isValidName($name))->toBeTrue();
})->with(['brand', 'SaaS_theme-2', 'custom']);

it('rejects theme names the panel does not load', function (string $name) {
    expect(ThemeFiles::isValidName($name))->toBeFalse();
})->with(['', 'my theme', '../brand', 'brand.min']);

it('uses the pattern app.blade.php checks martis.theme.name against', function () {
    $blade = (string) file_get_contents(__DIR__.'/../../resources/views/app.blade.php');

    expect($blade)->toContain("preg_match('".ThemeFiles::NAME_PATTERN."', \$themeName)");
});
